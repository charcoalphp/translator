<?php

namespace Charcoal\Tests\Translation\ServiceProvider;

// From Pimple
use Pimple\Container;

// From `charcoal-translator`
use Charcoal\Translator\Testing\AbstractTestCase;
use Charcoal\Translator\Middleware\LanguageMiddleware;
use Charcoal\Translator\ServiceProvider\TranslatorServiceProvider;
use Charcoal\Translator\LocalesManager;
use Charcoal\Translator\Translator;

/**
 *
 */
class TranslatorServiceProviderTest extends AbstractTestCase
{
    /**
     * Tested Class.
     *
     * @var TranslatorServiceProvider
     */
    private $obj;

    /**
     * Service Container.
     *
     * @var Container
     */
    private $container;

    /**
     * @return void
     */
    public function setUp()
    {
        $this->obj = new TranslatorServiceProvider();
        $this->container = new Container();
        $this->container['config'] = [
            'base_path' => realpath(__DIR__.'/../../..'),
            'locales'   => [
                'languages' => [
                    'en' => [ 'locale' => 'en-US' ],
                    'fr' => [ 'locale' => 'fr-FR' ]
                ],
                'default_language'   => 'en',
                'fallback_languages' => [ 'en' ]
            ],
            'translator' => [
                'loaders' => [
                    'csv',
                    'dat',
                    'res',
                    'ini',
                    'json',
                    'mo',
                    'php',
                    'po',
                    'qt',
                    'xliff',
                    'yaml'
                ],
                'paths' => [
                    '/fixtures/translations',
                    '/fixtures/foobar'
                ],
                'translations' => [
                    'messages' => [
                        'en' => [
                            'foo' => 'FOO'
                        ],
                        'fr' => [
                            'foo' => 'OOF'
                        ]
                    ]
                ],
                'debug' => false,
                'cache_dir' => 'translator_cache'
            ],
            'middlewares' => [
                'charcoal/translator/middleware/language' => []
            ]
        ];

        $this->container->register($this->obj);
    }

    /**
     * @return void
     */
    protected function resetDefaultLanguage()
    {
        static $raw;

        if ($raw === null) {
            $raw = $this->container->raw('locales/default-language');
        }

        unset($this->container['locales/default-language']);
        $this->container['locales/default-language'] = $raw;
    }

    /**
     * @return void
     */
    public function testKeys()
    {
        $this->assertFalse(isset($this->container['foofoobarbarbaz']));
        $this->assertTrue(isset($this->container['locales/config']));
        $this->assertTrue(isset($this->container['locales/available-languages']));
        $this->assertTrue(isset($this->container['locales/default-language']));
        $this->assertTrue(isset($this->container['translator/message-selector']));
        $this->assertTrue(isset($this->container['translator']));
        $this->assertTrue(isset($this->container['middlewares/charcoal/translator/middleware/language']));
    }

    /**
     * @return void
     */
    public function testAvailableLanguages()
    {
        $languages = $this->container['locales/available-languages'];
        $this->assertContains('en', $languages);
    }

    /**
     * @return void
     */
    public function testLanguages()
    {
        $languages = $this->container['locales/languages'];
        $this->assertArrayHasKey('en', $languages);
    }

    /**
     * @return void
     */
    public function testDefaultLanguage()
    {
        $defaultLanguage = $this->container['locales/default-language'];
        $this->assertEquals('en', $defaultLanguage);
    }

    /**
     * @return void
     */
    public function testFallbackLanguages()
    {
        $fallbackLanguages = $this->container['locales/fallback-languages'];
        $this->assertEquals([ 'en' ], $fallbackLanguages);
    }

    /**
     * @return void
     */
    public function testLanguageManager()
    {
        $manager = $this->container['locales/manager'];
        $this->assertInstanceOf(LocalesManager::class, $manager);
    }

    /**
     * @return void
     */
    public function testTranslator()
    {
        $translator = $this->container['translator'];
        $this->assertInstanceOf(Translator::class, $translator);
    }

    /**
     * @return void
     */
    public function testMiddleware()
    {
        $middleware = $this->container['middlewares/charcoal/translator/middleware/language'];
        $this->assertInstanceOf(LanguageMiddleware::class, $middleware);
    }
}
