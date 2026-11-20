<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Command;

use Dud\ContaoPdfPreview\Security\BlockedPdfRegistry;
use Dud\ContaoPdfPreview\Security\PdfApprovals;
use Dud\ContaoPdfPreview\Security\PdfSecurityScanner;
use Dud\ContaoPdfPreview\Security\ScanReason;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
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
        private readonly PdfApprovals $approvals,
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
            ->addOption('approve', null, InputOption::VALUE_NONE, 'Approve the given blocked file after manual review (applies to exactly this file content; virus findings cannot be approved)')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Revoke the approval of the given file')
            ->addOption('approvals', null, InputOption::VALUE_NONE, 'List all manual approvals')
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

        if ($input->getOption('approvals')) {
            return $this->listApprovals($io);
        }

        if ($input->getOption('approve') || $input->getOption('revoke')) {
            return $this->changeApproval($io, $path, (bool) $input->getOption('approve'));
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
                OutputFormatter::escape(ScanReason::formatAll($result->reasons, $this->translator, "\n")),
                OutputFormatter::escape(ScanReason::formatAll($result->notices, $this->translator, "\n")),
            ];
        }

        $io->table([$this->t('col_file'), $this->t('col_status'), $this->t('col_pages'), $this->t('col_virus'), $this->t('col_reasons'), $this->t('col_notices')], $rows);

        // Liste für den Hinweis auf der Backend-Startseite aktualisieren
        if (Path::canonicalize($path) === Path::join($this->projectDir, $this->uploadPath)) {
            $this->registry->scanAll();
        }

        if (!$this->scanner->hasVirusScanner()) {
            $io->warning($this->t('no_scanner'));
        }

        if ($input->getOption('purge-previews')) {
            // Im Browser gerenderte Seiten (pdf-client) bleiben erhalten: Sie lassen sich
            // auf Hostings ohne Server-Renderer nicht automatisch neu erzeugen
            if (is_dir($this->previewDir)) {
                foreach ((new Finder())->in($this->previewDir)->depth(0)->exclude('pdf-client') as $item) {
                    (new Filesystem())->remove($item->getPathname());
                }
            }

            $io->success($this->t('purged'));
        }

        if ($blocked > 0) {
            $io->warning($this->t('blocked_count', (string) $blocked, (string) \count($files)));
            $io->note($this->t('approve_hint'));

            return Command::FAILURE;
        }

        $io->success($this->t('success', (string) \count($files)));

        return Command::SUCCESS;
    }

    private function changeApproval(SymfonyStyle $io, string $path, bool $approve): int
    {
        if (!is_file($path)) {
            $io->error($this->t('approve_file'));

            return Command::FAILURE;
        }

        $relative = Path::makeRelative($path, $this->projectDir);

        if (!$approve) {
            $count = $this->approvals->revoke($relative);

            if ($hash = PdfSecurityScanner::fingerprint($path)) {
                $count += $this->approvals->revoke($hash);
            }

            $io->{$count > 0 ? 'success' : 'note'}($this->t($count > 0 ? 'revoked' : 'not_approved', $relative));
            $this->registry->scanAll(false);

            return Command::SUCCESS;
        }

        $result = $this->scanner->scan($path, false);

        if ($result->safe) {
            $io->note($this->t('approve_unneeded', $relative));

            return Command::SUCCESS;
        }

        foreach ($result->reasons as $reason) {
            if (\in_array(ScanReason::parse($reason)[0], ScanReason::NOT_APPROVABLE, true)) {
                $io->error($this->t('approve_denied', $relative, ScanReason::format($reason, $this->translator)));
                $io->note($this->t('approve_never'));

                return Command::FAILURE;
            }
        }

        $hash = PdfSecurityScanner::fingerprint($path);

        if (null === $hash) {
            $io->error($this->t('approve_file'));

            return Command::FAILURE;
        }

        // Befunde können Text aus der PDF enthalten: für die Konsole maskieren
        $io->listing(array_map(fn (string $r): string => OutputFormatter::escape(ScanReason::format($r, $this->translator)), $result->reasons));
        $this->approvals->approve($hash, $relative, $result->reasons);
        $after = $this->scanner->scan($path, false);
        $this->registry->scanAll();

        if (!$after->safe) {
            $io->error($this->t('approve_denied', $relative, ScanReason::formatAll($after->reasons, $this->translator)));

            return Command::FAILURE;
        }

        $io->success($this->t('approved', $relative));

        return Command::SUCCESS;
    }

    private function listApprovals(SymfonyStyle $io): int
    {
        $rows = [];

        foreach ($this->approvals->all() as $hash => $entry) {
            $rows[] = [OutputFormatter::escape($entry['path']), substr($hash, 0, 12).'…', $entry['date'], OutputFormatter::escape(ScanReason::formatAll($entry['reasons'], $this->translator, "\n"))];
        }

        if (!$rows) {
            $io->note($this->t('no_approvals'));

            return Command::SUCCESS;
        }

        $io->table([$this->t('col_file'), 'SHA-256', $this->t('col_date'), $this->t('col_reasons')], $rows);

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
            'col_notices' => 'Notices',
            'col_date' => 'Approved',
            'ok' => 'OK',
            'blocked' => 'BLOCKED',
            'no_scanner' => 'No virus scanner (ClamAV) found. Recommended: installing clamd and setting contao_pdf_preview.security.clamav_socket.',
            'purged' => 'Preview cache purged. Previews will be regenerated (and re-checked) on demand.',
            'blocked_count' => '%s of %s PDF(s) blocked.',
            'success' => '%s PDF(s) checked, no issues found.',
            'approve_hint' => 'After manual review, a file can be approved with: contao-pdf-preview:scan --approve <file>',
            'approve_file' => 'A single file must be given for --approve/--revoke.',
            'approve_unneeded' => '"%s" is not blocked, no approval needed.',
            'approve_denied' => '"%s" cannot be approved: %s',
            'approved' => '"%s" approved. The approval applies to exactly this file content and expires when the file is changed.',
            'revoked' => 'Approval for "%s" revoked.',
            'not_approved' => '"%s" was not approved.',
            'no_approvals' => 'No manual approvals.',
            'approve_never' => 'Virus findings, files that are not PDFs and polyglot files can never be approved.',
        ];

        $id = 'dud_pdf_preview.cli.'.$key;
        $text = $this->translator->trans($id, $parameters, 'contao_default');

        return $text !== $id ? $text : vsprintf($fallback[$key] ?? $key, $parameters);
    }
}
