<?php

namespace RarWowVendor\Mpdf;

use RarWowVendor\Mpdf\Color\ColorConverter;
use RarWowVendor\Mpdf\Color\ColorModeConverter;
use RarWowVendor\Mpdf\Color\ColorSpaceRestrictor;
use RarWowVendor\Mpdf\Css\BorderMerger;
use RarWowVendor\Mpdf\Css\CssMerger;
use RarWowVendor\Mpdf\Css\CssParser;
use RarWowVendor\Mpdf\Css\InlinePropertyConverter;
use RarWowVendor\Mpdf\Css\InlineStyleParser;
use RarWowVendor\Mpdf\Css\NormalizeProperties;
use RarWowVendor\Mpdf\Css\SelectorParser;
use RarWowVendor\Mpdf\Css\ShadowParser;
use RarWowVendor\Mpdf\File\LocalContentLoader;
use RarWowVendor\Mpdf\Fonts\FontCache;
use RarWowVendor\Mpdf\Fonts\FontFileFinder;
use RarWowVendor\Mpdf\Http\CurlHttpClient;
use RarWowVendor\Mpdf\Http\SocketHttpClient;
use RarWowVendor\Mpdf\Image\ImageProcessor;
use RarWowVendor\Mpdf\Pdf\Protection;
use RarWowVendor\Mpdf\Pdf\Protection\UniqidGenerator;
use RarWowVendor\Mpdf\Writer\BaseWriter;
use RarWowVendor\Mpdf\Writer\BackgroundWriter;
use RarWowVendor\Mpdf\Writer\ColorWriter;
use RarWowVendor\Mpdf\Writer\BookmarkWriter;
use RarWowVendor\Mpdf\Writer\FontWriter;
use RarWowVendor\Mpdf\Writer\FormWriter;
use RarWowVendor\Mpdf\Writer\ImageWriter;
use RarWowVendor\Mpdf\Writer\JavaScriptWriter;
use RarWowVendor\Mpdf\Writer\MetadataWriter;
use RarWowVendor\Mpdf\Writer\OptionalContentWriter;
use RarWowVendor\Mpdf\Writer\PageWriter;
use RarWowVendor\Mpdf\Writer\ResourceWriter;
use RarWowVendor\Psr\Log\LoggerInterface;

class ServiceFactory
{

	/**
	 * @var \RarWowVendor\Mpdf\Container\ContainerInterface|null
	 */
	private $container;

	public function __construct($container = null)
	{
		$this->container = $container;
	}

	public function getServices(
		Mpdf $mpdf,
		LoggerInterface $logger,
		$config,
		$languageToFont,
		$scriptToLanguage,
		$fontDescriptor,
		$bmp,
		$directWrite,
		$wmf
	) {
		$sizeConverter = new SizeConverter($mpdf->dpi, $mpdf->default_font_size, $mpdf, $logger);

		$colorModeConverter = new ColorModeConverter();
		$colorSpaceRestrictor = new ColorSpaceRestrictor(
			$mpdf,
			$colorModeConverter
		);
		$colorConverter = new ColorConverter($mpdf, $colorModeConverter, $colorSpaceRestrictor);

		$tableOfContents = new TableOfContents($mpdf, $sizeConverter);

		$cacheBasePath = $config['tempDir'] . '/mpdf';

		$cache = new Cache($cacheBasePath, $config['cacheCleanupInterval']);
		$fontCache = new FontCache(new Cache($cacheBasePath . '/ttfontdata', $config['cacheCleanupInterval']));

		$fontFileFinder = new FontFileFinder($config['fontDir']);

		if ($this->container && $this->container->has('httpClient')) {
			$httpClient = $this->container->get('httpClient');
		} elseif (\function_exists('curl_init')) {
			$httpClient = new CurlHttpClient($mpdf, $logger);
		} else {
			$httpClient = new SocketHttpClient($logger);
		}

		$localContentLoader = $this->container && $this->container->has('localContentLoader')
			? $this->container->get('localContentLoader')
			: new LocalContentLoader();

		$assetFetcher = $this->container && $this->container->has('assetFetcher')
			? $this->container->get('assetFetcher')
			: new AssetFetcher($mpdf, $localContentLoader, $httpClient, $logger);

		$normalizeProperties = new NormalizeProperties($mpdf, $sizeConverter, $colorConverter);
		$selectorParser = new SelectorParser($mpdf);
		$inlineStyleParser = new InlineStyleParser($normalizeProperties);
		$inlinePropertyConverter = new InlinePropertyConverter($colorConverter);
		$borderMerger = new BorderMerger();

		$cssParser = new CssParser($mpdf, $cache, $sizeConverter, $colorConverter, $assetFetcher);

		$cssMerger = new CssMerger(
			$mpdf,
			$normalizeProperties,
			$inlineStyleParser,
			$selectorParser,
			$inlinePropertyConverter,
			$colorConverter,
			$borderMerger
		);

		$cssManager = new CssManager($cssParser, $cssMerger);

		$otl = new Otl($mpdf, $fontCache);

		$protection = new Protection(new UniqidGenerator());

		$writer = new BaseWriter($mpdf, $protection);

		$gradient = new Gradient($mpdf, $sizeConverter, $colorConverter, $writer);

		$formWriter = new FormWriter($mpdf, $writer);

		$form = new Form($mpdf, $otl, $colorConverter, $writer, $formWriter);

		$hyphenator = new Hyphenator($mpdf);

		$imageProcessor = new ImageProcessor(
			$mpdf,
			$otl,
			$cssManager,
			$sizeConverter,
			$colorConverter,
			$colorModeConverter,
			$cache,
			$languageToFont,
			$scriptToLanguage,
			$assetFetcher,
			$logger
		);

		$tag = new Tag(
			$mpdf,
			$cache,
			$cssManager,
			$form,
			$otl,
			$tableOfContents,
			$sizeConverter,
			$colorConverter,
			$imageProcessor,
			$languageToFont
		);

		$fontWriter = new FontWriter($mpdf, $writer, $fontCache, $fontDescriptor);
		$metadataWriter = new MetadataWriter($mpdf, $writer, $form, $protection, $logger);
		$imageWriter = new ImageWriter($mpdf, $writer);
		$pageWriter = new PageWriter($mpdf, $form, $writer, $metadataWriter);
		$bookmarkWriter = new BookmarkWriter($mpdf, $writer);
		$optionalContentWriter = new OptionalContentWriter($mpdf, $writer);
		$colorWriter = new ColorWriter($mpdf, $writer);
		$backgroundWriter = new BackgroundWriter($mpdf, $writer);
		$javaScriptWriter = new JavaScriptWriter($mpdf, $writer);

		$resourceWriter = new ResourceWriter(
			$mpdf,
			$writer,
			$colorWriter,
			$fontWriter,
			$imageWriter,
			$formWriter,
			$optionalContentWriter,
			$backgroundWriter,
			$bookmarkWriter,
			$metadataWriter,
			$javaScriptWriter,
			$logger
		);

		return [
			'otl' => $otl,
			'bmp' => $bmp,
			'cache' => $cache,
			'cssManager' => $cssManager,
			'directWrite' => $directWrite,
			'fontCache' => $fontCache,
			'fontFileFinder' => $fontFileFinder,
			'form' => $form,
			'gradient' => $gradient,
			'tableOfContents' => $tableOfContents,
			'tag' => $tag,
			'wmf' => $wmf,
			'sizeConverter' => $sizeConverter,
			'colorConverter' => $colorConverter,
			'hyphenator' => $hyphenator,
			'localContentLoader' => $localContentLoader,
			'httpClient' => $httpClient,
			'assetFetcher' => $assetFetcher,
			'imageProcessor' => $imageProcessor,
			'protection' => $protection,

			'languageToFont' => $languageToFont,
			'scriptToLanguage' => $scriptToLanguage,

			'writer' => $writer,
			'fontWriter' => $fontWriter,
			'metadataWriter' => $metadataWriter,
			'imageWriter' => $imageWriter,
			'formWriter' => $formWriter,
			'pageWriter' => $pageWriter,
			'bookmarkWriter' => $bookmarkWriter,
			'optionalContentWriter' => $optionalContentWriter,
			'colorWriter' => $colorWriter,
			'backgroundWriter' => $backgroundWriter,
			'javaScriptWriter' => $javaScriptWriter,
			'resourceWriter' => $resourceWriter
		];
	}

	public function getServiceIds()
	{
		return [
			'otl',
			'bmp',
			'cache',
			'cssManager',
			'directWrite',
			'fontCache',
			'fontFileFinder',
			'form',
			'gradient',
			'tableOfContents',
			'tag',
			'wmf',
			'sizeConverter',
			'colorConverter',
			'hyphenator',
			'localContentLoader',
			'httpClient',
			'assetFetcher',
			'imageProcessor',
			'protection',
			'languageToFont',
			'scriptToLanguage',
			'writer',
			'fontWriter',
			'metadataWriter',
			'imageWriter',
			'formWriter',
			'pageWriter',
			'bookmarkWriter',
			'optionalContentWriter',
			'colorWriter',
			'backgroundWriter',
			'javaScriptWriter',
			'resourceWriter',
		];
	}

}
