<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Dud\ContaoPdfPreview\Security\BlockedPdfRegistry;

/**
 * Prüft regelmäßig alle PDFs im Dateiverzeichnis – auch solche, die per FTP oder
 * Deployment am Contao-Upload vorbei abgelegt wurden. Bereits geprüfte, unveränderte
 * Dateien kommen aus dem Cache (Inhalts-Hash), daher ist der Lauf günstig. Blockierte
 * Dateien erscheinen im System-Log und als Hinweis auf der Backend-Startseite.
 */
#[AsCronJob('hourly')]
class PdfSecurityCron
{
    public function __construct(private readonly BlockedPdfRegistry $registry)
    {
    }

    public function __invoke(): void
    {
        $this->registry->scanAll();
    }
}
