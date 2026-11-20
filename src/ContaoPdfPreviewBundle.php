<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview;

use Dud\ContaoPdfPreview\DependencyInjection\Compiler\PdfExtensionsPass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class ContaoPdfPreviewBundle extends AbstractBundle
{
    protected string $extensionAlias = 'contao_pdf_preview';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
            ->integerNode('preview_size')
            ->info('Minimum edge length (px) of the internally rendered PDF page. Contao derives all responsive image sizes from it. Values are rounded up to 512, 1024, 2048 …')
            ->min(256)
            ->max(8192)
            ->defaultValue(2048)
            ->end()
            ->integerNode('default_page')
            ->info('Default PDF page used as image (can be overridden per file in the file manager).')
            ->min(1)
            ->defaultValue(1)
            ->end()
            ->booleanNode('backend_fields')
            ->info('Allow PDF files in all image selection fields (fileTree with image extensions) of the back end.')
            ->defaultTrue()
            ->end()
            ->arrayNode('excluded_fields')
            ->info('Fields ("table.field", wildcards allowed) in which PDFs must not be selectable.')
            ->scalarPrototype()->end()
            ->defaultValue(['tl_theme.screenshot'])
            ->end()
            ->booleanNode('gallery')
            ->info('Include PDFs in the "image"/"gallery" content elements (also from selected folders).')
            ->defaultTrue()
            ->end()
            ->booleanNode('lightbox')
            ->info('Allow "full-size view/new window" for PDF preview images (shows the large preview in the lightbox).')
            ->defaultTrue()
            ->end()
            ->scalarNode('cli_binary')
            ->info('Optional path to "pdftoppm" or "gs" as fallback if Imagick cannot render PDFs. Empty = detect automatically, false = disable.')
            ->defaultNull()
            ->end()
            ->arrayNode('security')
            ->info('Security check before a PDF is rendered (also applies to core previews, e.g. in the download element).')
            ->addDefaultsIfNotSet()
            ->children()
            ->booleanNode('enabled')
            ->info('Enable the security check. Strongly recommended.')
            ->defaultTrue()
            ->end()
            ->integerNode('max_file_size')
            ->info('Maximum file size in bytes.')
            ->min(1024)
            ->defaultValue(50 * 1024 * 1024)
            ->end()
            ->integerNode('max_decompressed_size')
            ->info('Maximum total size of decompressed object streams in bytes. 0 = automatic: each stream may use a quarter of the free memory_limit, compression bombs are detected by their ratio.')
            ->min(0)
            ->defaultValue(0)
            ->end()
            ->booleanNode('allow_form_features')
            ->info('Treat form JavaScript that only calls the standard Acrobat form functions (AFDate_FormatEx, AFNumber_Format …) and SubmitForm as harmless notices instead of blocking.')
            ->defaultTrue()
            ->end()
            ->arrayNode('blocked_features')
            ->info('PDF names that lead to blocking (active content).')
            ->scalarPrototype()->end()
            ->defaultValue(['JavaScript', 'JS', 'Launch', 'EmbeddedFile', 'EmbeddedFiles', 'RichMedia', 'XFA', 'SubmitForm', 'ImportData', 'GoToE'])
            ->end()
            ->booleanNode('allow_encrypted')
            ->info('Allow encrypted PDFs (their content cannot be inspected).')
            ->defaultFalse()
            ->end()
            ->enumNode('virus_scan')
            ->info('"auto": use ClamAV if available; "clamav": ClamAV is required; "off": no virus scan.')
            ->values(['auto', 'clamav', 'off'])
            ->defaultValue('auto')
            ->end()
            ->booleanNode('require_virus_scan')
            ->info('Block PDFs if no virus scanner is available or the scan fails.')
            ->defaultFalse()
            ->end()
            ->scalarNode('clamav_socket')
            ->info('clamd socket, e.g. "/run/clamd.scan/clamd.sock" or "tcp://127.0.0.1:3310". Empty = look for clamdscan/clamscan.')
            ->defaultNull()
            ->end()
            ->scalarNode('clamav_binary')
            ->info('Optional path to clamdscan or clamscan.')
            ->defaultNull()
            ->end()
            ->end()
            ->end()
            ->end()
        ;
    }

    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        // Contao begrenzt Vorschaubilder standardmäßig auf 1024 px. Für scharfe
        // responsive Bilder (2x-Dichten) heben wir die Grenze an. Eine eigene
        // contao.image.preview.max_size in der config.yaml hat weiterhin Vorrang.
        $configs = $builder->getExtensionConfig($this->extensionAlias);
        $size = 2048;

        foreach ($configs as $config) {
            if (isset($config['preview_size'])) {
                $size = (int) $config['preview_size'];
            }
        }

        $builder->prependExtensionConfig('contao', [
            'image' => ['preview' => ['max_size' => max(1024, $size)]],
        ]);
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.yaml');
        $container->parameters()
            ->set('contao_pdf_preview.preview_size', $config['preview_size'])
            ->set('contao_pdf_preview.default_page', $config['default_page'])
            ->set('contao_pdf_preview.backend_fields', $config['backend_fields'])
            ->set('contao_pdf_preview.excluded_fields', $config['excluded_fields'])
            ->set('contao_pdf_preview.gallery', $config['gallery'])
            ->set('contao_pdf_preview.lightbox', $config['lightbox'])
            ->set('contao_pdf_preview.cli_binary', $config['cli_binary'])
            ->set('contao_pdf_preview.image_extensions', ['jpg', 'jpeg', 'gif', 'png', 'tif', 'tiff', 'bmp', 'svg', 'svgz', 'webp', 'avif', 'pdf'])
        ;

        foreach ($config['security'] as $key => $value) {
            $container->parameters()->set('contao_pdf_preview.security.'.$key, $value);
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new PdfExtensionsPass());
    }
}
