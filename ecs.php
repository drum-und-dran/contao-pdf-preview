<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

use PhpCsFixer\Fixer\Comment\HeaderCommentFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

/*
 * Contao-Coding-Standard. Einrichtung und Aufruf:
 *
 *   composer --working-dir=tools/ecs install
 *   tools/ecs/vendor/bin/ecs check          (prüfen)
 *   tools/ecs/vendor/bin/ecs check --fix    (korrigieren)
 */
return ECSConfig::configure()
    ->withSets([__DIR__.'/tools/ecs/vendor/contao/easy-coding-standard/config/contao.php'])
    ->withPaths([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/contao', __DIR__.'/ecs.php'])
    ->withConfiguredRule(HeaderCommentFixer::class, ['header' => "Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de\n\n@license MIT"])
    ->withParallel()
    ->withCache(sys_get_temp_dir().'/ecs_dud_contao_pdf_preview')
;
