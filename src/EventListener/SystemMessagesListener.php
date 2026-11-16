<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\EventListener;

use Contao\CoreBundle\DependencyInjection\Attribute\AsHook;
use Contao\StringUtil;
use Dud\ContaoPdfPreview\Security\BlockedPdfRegistry;
use Dud\ContaoPdfPreview\Security\ScanReason;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Zeigt Administratoren auf der Backend-Startseite, wenn im Dateiverzeichnis
 * blockierte PDFs liegen (z. B. per FTP hochgeladen).
 */
class SystemMessagesListener
{
    public function __construct(
        private readonly BlockedPdfRegistry $registry,
        private readonly Security $security,
    ) {
    }

    #[AsHook('getSystemMessages')]
    public function __invoke(): string
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return '';
        }

        $blocked = $this->registry->getBlocked();

        if (!$blocked) {
            return '';
        }

        $lang = $GLOBALS['TL_LANG']['dud_pdf_preview'] ?? [];
        $items = '';

        foreach (\array_slice($blocked, 0, 20, true) as $path => $reasons) {
            $items .= '<li><code>'.StringUtil::specialchars((string) $path).'</code> – '.StringUtil::specialchars(ScanReason::formatAll($reasons)).'</li>';
        }

        if (\count($blocked) > 20) {
            $items .= '<li>…</li>';
        }

        return '<p class="tl_error">'.StringUtil::specialchars(\sprintf($lang['systemMessage'] ?? '%d PDF file(s) were blocked by the security check and will not be processed as images. Please review and delete them if necessary:', \count($blocked))).'</p><ul class="tl_listing" style="margin:0 0 12px 18px;list-style:disc">'.$items.'</ul>';
    }
}
