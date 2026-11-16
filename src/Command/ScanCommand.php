<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Command;

use Dud\ContaoPdfPreview\Security\BlockedPdfRegistry;
use Dud\ContaoPdfPreview\Security\PdfSecurityScanner;
use Dud\ContaoPdfPreview\Security\ScanReason;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Prüft alle PDFs (z. B. regelmäßig per Cronjob mit aktualisierten
 * Virensignaturen) und leert bei Bedarf den Vorschau-Cache.
 */
#[AsCommand(name: 'contao-pdf-preview:scan', description: 'Checks PDF files with the security check of dud/contao-pdf-preview.')]
class ScanCommand extends Command
{
    public function __construct(
        private readonly PdfSecurityScanner $scanner,
        private readonly BlockedPdfRegistry $registry,
        private readonly string $projectDir,
        private readonly string $uploadPath,
        private readonly string $previewDir,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::OPTIONAL, 'File or folder (relative to the project directory)', $this->uploadPath)
            ->addOption('no-cache', null, InputOption::VALUE_NONE, 'Ignore cached scan results')
            ->addOption('purge-previews', null, InputOption::VALUE_NONE, 'Purge the preview cache (assets/previews), e.g. after configuration changes')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = Path::makeAbsolute((string) $input->getArgument('path'), $this->projectDir);

        if (!Path::isBasePath($this->projectDir, $path) || !file_exists($path)) {
            $io->error($this->t('path'));

            return Command::FAILURE;
        }

        $files = is_file($path)
            ? [$path]
            : array_map(static fn ($f) => $f->getPathname(), iterator_to_array((new Finder())->files()->in($path)->name('/\.pdf$/i')->followLinks(), false));

        $rows = [];
        $blocked = 0;

        foreach ($files as $file) {
            $result = $this->scanner->scan($file, !$input->getOption('no-cache'));

            if (!$result->safe) {
                ++$blocked;
            }

            $rows[] = [
                Path::makeRelative($file, $this->projectDir),
                $result->safe ? '<info>'.$this->t('ok').'</info>' : '<error>'.$this->t('blocked').'</error>',
                $result->pageCount ?? '?',
                $result->virusScanner ?? '–',
                ScanReason::formatAll($result->reasons, $this->translator, "\n"),
            ];
        }

        $io->table([$this->t('col_file'), $this->t('col_status'), $this->t('col_pages'), $this->t('col_virus'), $this->t('col_reasons')], $rows);

        // Liste für den Hinweis auf der Backend-Startseite aktualisieren
        if (Path::canonicalize($path) === Path::join($this->projectDir, $this->uploadPath)) {
            $this->registry->scanAll();
        }

        if (!$this->scanner->hasVirusScanner()) {
            $io->warning($this->t('no_scanner'));
        }

        if ($input->getOption('purge-previews')) {
            // Im Browser gerenderte Seiten (pdf-client) bleiben erhalten: Sie lassen
            // sich auf Hostings ohne Server-Renderer nicht automatisch neu erzeugen
            if (is_dir($this->previewDir)) {
                foreach ((new Finder())->in($this->previewDir)->depth(0)->exclude('pdf-client') as $item) {
                    (new Filesystem())->remove($item->getPathname());
                }
            }
            $io->success($this->t('purged'));
        }

        if ($blocked > 0) {
            $io->warning($this->t('blocked_count', (string) $blocked, (string) \count($files)));

            return Command::FAILURE;
        }

        $io->success($this->t('success', (string) \count($files)));

        return Command::SUCCESS;
    }

    private function t(string $key, string ...$parameters): string
    {
        $fallback = [
            'path' => 'Path not found or outside the project.',
            'col_file' => 'File',
            'col_status' => 'Status',
            'col_pages' => 'Pages',
            'col_virus' => 'Virus scan',
            'col_reasons' => 'Reasons',
            'ok' => 'OK',
            'blocked' => 'BLOCKED',
            'no_scanner' => 'No virus scanner (ClamAV) found. Recommended: installing clamd and setting contao_pdf_preview.security.clamav_socket.',
            'purged' => 'Preview cache purged. Previews will be regenerated (and re-checked) on demand.',
            'blocked_count' => '%s of %s PDF(s) blocked.',
            'success' => '%s PDF(s) checked, no issues found.',
        ];

        $id = 'dud_pdf_preview.cli.'.$key;
        $text = $this->translator->trans($id, $parameters, 'contao_default');

        return $text !== $id ? $text : vsprintf($fallback[$key] ?? $key, $parameters);
    }
}
