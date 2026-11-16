<?php

declare(strict_types=1);

/*
 * Contao PDF Preview – (c) Gunnar Haeuschkel / Drum und Dran, https://drum-und-dran.de
 *
 * @license MIT
 */

namespace Dud\ContaoPdfPreview\ContaoManager;

use Contao\CalendarBundle\ContaoCalendarBundle;
use Contao\CoreBundle\ContaoCoreBundle;
use Contao\ManagerPlugin\Bundle\BundlePluginInterface;
use Contao\ManagerPlugin\Bundle\Config\BundleConfig;
use Contao\ManagerPlugin\Bundle\Parser\ParserInterface;
use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use Contao\NewsBundle\ContaoNewsBundle;
use Dud\ContaoPdfPreview\ContaoPdfPreviewBundle;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\RouteCollection;

final class Plugin implements BundlePluginInterface, RoutingPluginInterface
{
    public function getBundles(ParserInterface $parser): array
    {
        // Nach News/Calendar laden, damit deren DCA-Felder sicher existieren.
        $loadAfter = array_values(array_filter(
            [ContaoCoreBundle::class, ContaoNewsBundle::class, ContaoCalendarBundle::class, 'Contao\FaqBundle\ContaoFaqBundle'],
            'class_exists',
        ));

        return [
            BundleConfig::create(ContaoPdfPreviewBundle::class)->setLoadAfter($loadAfter),
        ];
    }

    public function getRouteCollection(LoaderResolverInterface $resolver, KernelInterface $kernel): RouteCollection|null
    {
        $path = __DIR__.'/../Controller/Backend';

        $loader = $resolver->resolve($path, 'attribute');

        return $loader ? $loader->load($path) : null;
    }
}
