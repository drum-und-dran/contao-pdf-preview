<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\System;
use Dud\ContaoPdfPreview\Hosting\HostingCheck;
use Dud\ContaoPdfPreview\Hosting\ResourceLimits;
use Dud\ContaoPdfPreview\Image\PreviewCleaner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Zeigt, was das Hosting für die PDF-Vorschau leistet, und räumt bei Bedarf den
 * Vorschau-Cache auf.
 */
#[AsCommand(name: 'contao-pdf-preview:check', description: 'Checks the hosting capabilities and limits for dud/contao-pdf-preview.')]
class CheckCommand extends Command
{
    public function __construct(
        private readonly HostingCheck $check,
        private readonly PreviewCleaner $cleaner,
        private readonly ContaoFramework $framework,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('cleanup', null, InputOption::VALUE_NONE, 'Remove previews of deleted or replaced PDFs and unused crops');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Sprachdateien laden (Sprache der Konsole: Standardsprache von Contao)
        try {
            $this->framework->initialize();
            $this->framework->getAdapter(System::class)->loadLanguageFile('default');
        } catch (\Throwable) {
        }

        $io->title(HostingCheck::text('title'));

        if ($input->getOption('cleanup')) {
            $stats = $this->cleaner->cleanup();
            $io->success(HostingCheck::text('cleanup', (string) $stats['files'], ResourceLimits::formatBytes($stats['bytes'])));
        }

        $rows = [];

        foreach ($this->check->run(true) as $result) {
            $text = HostingCheck::describe($result);

            $tag = match ($result['status']) {
                HostingCheck::OK => 'info',
                HostingCheck::WARNING => 'comment',
                default => 'error',
            };

            $rows[] = [$text['label'], '<'.$tag.'>'.$text['status'].'</'.$tag.'>', $text['value'], wordwrap($text['recommendation'], 70)];
        }

        $io->table([HostingCheck::text('col_check'), HostingCheck::text('col_status'), HostingCheck::text('col_value'), HostingCheck::text('col_recommendation')], $rows);
        $io->note('PHP '.PHP_VERSION.' ('.\PHP_SAPI.'). Web requests may use different limits than the console (php.ini of PHP-FPM).');

        return Command::SUCCESS;
    }
}
