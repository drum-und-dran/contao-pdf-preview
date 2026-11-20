<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Dud\ContaoPdfPreview\Image\PreviewCleaner;

/**
 * Räumt täglich verwaiste und veraltete Vorschaubilder auf, damit der Webspace
 * nicht vollläuft.
 */
#[AsCronJob('daily')]
class PreviewCleanupCron
{
    public function __construct(private readonly PreviewCleaner $cleaner)
    {
    }

    public function __invoke(): void
    {
        $this->cleaner->cleanup();
    }
}
