<?php
namespace Charcoal\Translator\Script;

use Pimple\Container;

// PSR-7 (http messaging) dependencies
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

// Intra-module (`charcoal-admin`) dependencies
use Charcoal\Admin\AdminScript;

use Charcoal\Translator\TranslatorAwareTrait;

/**
 * Find all strings to be translated in templates
 */
class TranslationParserScript extends AdminScript
{
    use TranslatorAwareTrait;

    /**
     * App configurations from dependencies
     * @var AppConfig
     */
    private $appConfig;

    /**
     * @var string $fileType
     */
    protected $fileType;

    /**
     * Output file
     * @var string $output
     */
    protected $output;

    /**
     * @var array $paths
     */
    protected $paths;

    /**
     * @var string $path
     */
    protected $path;

    /**
     * Path where the CSV file will be
     * Full path with base path.
     * @var string
     */
    protected $filePath;

    /**
     * @var array $locales
     */
    protected $locales;

    /**
     * Inject dependencies from a DI Container.
     *
     * @param  Container $container A dependencies container instance.
     * @return void
     */
    public function setDependencies(Container $container)
    {
        $this->appConfig = $container['config'];
        $this->setTranslator($container['translator']);

        parent::setDependencies($container);
    }

    /**
     * Arguments that can be use in the script.
     * If no path is provided, all views path are parsed.
     * If you do precise a path, notice that you will loose
     * all modified translations that comes from other paths.
     *
     * Valid arguments:
     * - output : path-to-csv/
     * - domain : filename prefix
     * - recursive : level of recursiveness (how deep the glob checks for the strings)
     * - path : Path to get translation from a precise location (i.e: templates/emails/)
     * - type : file type (either mustache or php)
     *
     * @todo Support php file type.
     * @return array
     */
    public function defaultArguments()
    {
        $arguments = [
            'output' => [
                'prefix' => 'o',
                'longPrefix' => 'output',
                'description' => 'Output file path. Make sure the path exists in the translator paths definition. (Default: translation/)',
                'defaultValue' => 'translations/'
            ],
            'domain' => [
                'prefix' => 'd',
                'longPrefix' => 'domain',
                'description' => 'Doman for the csv file. Based on symfony/translator CsvLoader.',
                'defaultValue' => 'messages'
            ],
            'recursive' => [
                'prefix' => 'r',
                'longPrefix' => 'recursive-level',
                'description' => 'Max recursive level for the glob operation on folders.',
                'defaultValue' => 4
            ],
            'path' => [
                'longPrefix'   => 'path',
                'prefix'  => 'p',
                'description'  => 'Path relative to the project installation (ex: templates/*/*/)',
                'defaultValue' => false
            ],
            'type' => [
                'longPrefix'   => 'type',
                'prefix'  => 't',
                'description'  => 'File type (mustache || php)',
                'defaultValue' => 'mustache'
            ]
        ];

        $arguments = array_merge(parent::defaultArguments(), $arguments);
        return $arguments;
    }

    /**
     * @param RequestInterface  $request  A PSR-7 compatible Request instance.
     * @param ResponseInterface $response A PSR-7 compatible Response instance.
     * @return ResponseInterface
     */
    public function run(RequestInterface $request, ResponseInterface $response)
    {
        // Unused
        unset($request);

        // Parse arguments
        $this->climate()->arguments->parse();
        $this->displayInformations();

        // Get translations
        $translations = $this->getTranslations();

        // Output to CSV file.
        $this->toCSV($translations);

        // Warn the user
        $base = $this->appConfig->get('base_path');
        $output = $this->output();
        $filePath = str_replace('/', DIRECTORY_SEPARATOR, $base.$output);

        $this->climate()->backgroundGreen()->out(
            'Make sure to include <light_green>'.$filePath.'</light_green> in your <light_green>translator/paths</light_green> configurations.'
        );

        return $response;
    }

    /**
     * Give feedback about what's going on.
     *
     * @return self.
     */
    protected function displayInformations()
    {
        $this->climate()->underline()->out(
            'Initializing translations parser script…'
        );

        $this->climate()->green()->out(
            'CSV file output: <white>'.$this->filePath().'</white>'
        );

        $this->climate()->green()->out(
            'CSV file names: <white>'.$this->domain().'.{locale}.csv</white>'
        );

        $this->climate()->green()->out(
            'Looping through <white>'.$this->maxRecursiveLevel().'</white> level of folders'
        );

        $this->climate()->green()->out(
            'File type parsed: <white>mustache</white>'
        );

        return $this;
    }

    /**
     * Complete filepath to the CSV location.
     * @return string Filepath to the csv.
     */
    protected function filePath()
    {
        if ($this->filePath) {
            return $this->filePath;
        }

        $base = $this->appConfig->get('base_path');
        $output = $this->output();
        $this->filePath = str_replace('/', DIRECTORY_SEPARATOR, $base.$output);
        return $this->filePath;
    }

    /**
     * Available locales (languages)
     * @return array Locales.
     */
    protected function locales()
    {
        return $this->translator()->availableLocales();
    }

    /**
     * @return string Current locale.
     */
    protected function locale()
    {
        return $this->translator()->getLocale();
    }

    /**
     * @return string
     */
    public function output()
    {
        if ($this->output) {
            return $this->output;
        }
        $output = $this->argOrInput('output');
        $this->output = $output;
        return $this->output;
    }

    /**
     * Domain which is the csv file name prefix.
     *
     * @return string
     */
    public function domain()
    {
        return $this->argOrInput('domain');
    }

    /**
     * Regex to match in files.
     *
     * @param  string $type File type (Mustache, PHP).
     * @return string Regex string.
     */
    public function regEx($type)
    {
        switch ($type) {
            case 'php':
                return '/([^\d\wA-Za-z])_t\(\s*\n*\r*(["\'])(?<text>(.|\n|\r|\n\r)*?)\2\s*\n*\r*\)/i';

            case 'ms':
            case 'mustache':
                return '/{{\s*#\s*_t\s*}}((.|\n|\r|\n\r)*?){{\s*\/\s*_t\s*}}/i';

            default:
                return '/{{\s*#\s*_t\s*}}((.|\n|\r|\n\r)*?){{\s*\/\s*_t\s*}}/i';
        }
    }

    /**
     * Loop through all paths to get translations.
     * Also merge with already existing translations.
     * Translations associated with the locale.
     * [
     *    'fr' => [
     *        'string' => 'translation',
     *        'string' => 'translation',
     *        'string' => 'translation',
     *        'string' => 'translation'
     *    ],
     *    'en' => [
     *        'string' => 'translation',
     *        'string' => 'translation',
     *        'string' => 'translation',
     *        'string' => 'translation'
     *    ]
     * ]
     * @return array Translations.
     */
    public function getTranslations()
    {
        $path = $this->path();

        if ($path) {
            $this->climate()->green()->out('Parsing files in <white>'.$path.'</white>');
            $translations = $this->getTranslationsFromPath($path, 'mustache');
            return $translations;
        }
        $paths = $this->paths();

        $translations = [];
        foreach ($paths as $p) {
            $this->climate()->green()->out('Parsing files in <white>'.$p.'</white>');
            $translations = array_merge_recursive($translations, $this->getTranslationsFromPath($p, 'mustache'));
        }

        return $translations;
    }

    /**
     * Get all translations in given path for the given file extension (mustache | php).
     * @param  string $path     The path.
     * @param  string $fileType The file extension|type.
     * @return array Translations.
     */
    public function getTranslationsFromPath($path, $fileType)
    {
        // remove vendor/locomotivemtl/charcoal-app
        $base = $this->appConfig->get('base_path');
        $glob = $this->globRecursive($base.$path.'*.'.$fileType);
        $regex = $this->regEx($fileType);


        $translations = [];

        // Array index for the preg_match.
        $index = 1;

        $this->climate()->inline('.');
        // Loop files to get original text.
        foreach ($glob as $k => $file) {
            $this->climate()->inline('.');
            $text = file_get_contents($file);
            if (preg_match($regex, $text)) {
                preg_match_all($regex, $text, $array);

                $i = 0;
                $t = count($array[$index]);
                $locales = $this->locales();

                for (; $i<$t; $i++) {
                    $this->climate()->inline('.');
                    $orig = $array[$index][$i];
                    foreach ($locales as $lang) {
                        $this->climate()->inline('.');
                        $this->translator()->setLocale($lang);
                        // By calling translate, we make sure all existings translations are taking into consideration.
                        $translations[$lang][$orig] = $this->translator()->translate($orig);
                    }
                }
            }
        }

        $this->climate()->out('.');
        $this->climate()->green()->out('Translations parsed from '.$path);
        return $translations;
    }

    /**
     * @param string  $pattern The pattern to search.
     * @param integer $flags   The glob flags.
     * @return array
     * @see http://in.php.net/manual/en/function.glob.php#106595
     */
    public function globRecursive($pattern, $flags = 0)
    {
        $max = $this->maxRecursiveLevel();
        $i = 1;
        $files = glob($pattern, $flags);
        foreach (glob(dirname($pattern).'/*', (GLOB_ONLYDIR|GLOB_NOSORT)) as $dir) {
            $files = array_merge($files, $this->globRecursive($dir.'/'.basename($pattern), $flags));
            $i++;
            if ($i >= $max) {
                break;
            }
        }
        return $files;
    }

    /**
     * Custom path
     * @return [type] [description]
     */
    public function path()
    {
        if ($this->climate()->arguments->defined('path')) {
            $this->path = $this->climate()->arguments->get('path');
        }
        return $this->path;
    }

    /**
     * @return string
     */
    public function paths()
    {
        if (!$this->paths) {
            $this->paths = $this->appConfig->get('view.paths');
        }
        return $this->paths;
    }

    /**
     * @return string
     */
    public function fileTypes()
    {
        if (!$this->fileTypes) {
            $this->fileTypes = [
                'php',
                'mustache'
            ];
        }
        return $this->fileTypes;
    }

    /**
     * @param  array $translations The translations to save in CSV.
     * @return self
     */
    public function toCSV(array $translations)
    {
        if (!count($translations)) {
            $this->climate()->error('
                There was no translations in the provided path ('.$this->path().')
                with the given recursive level ('.$this->maxRecursiveLevel().')
            ');
            return $this;
        }
        $base = $this->appConfig->get('base_path');
        $output = $this->output();
        $domain = $this->domain();

        $separator = $this->separator();
        $enclosure = $this->enclosure();

        foreach ($translations as $lang => $trans) {
            // Create / open the handle
            $filePath = str_replace('/', DIRECTORY_SEPARATOR, $base.$output);
            $dirname = dirname($filePath);

            if (!file_exists($filePath)) {
                mkdir($filePath, 0755, true);
            }
            $file = fopen($base.$output.$domain.'.'.$lang.'.csv', 'w');
            if (!$file) {
                continue;
            }

            foreach ($trans as $key => $translation) {
                $data = [ $key, $translation ];
                fputcsv($file, $data, $separator, $enclosure);
            }
            fclose($file);
        }


        return $this;
    }

    /**
     * @return string
     */
    public function enclosure()
    {
        return '"';
    }

    /**
     * @return string
     */
    public function separator()
    {
        return ';';
    }

    /**
     * @return integer
     */
    public function maxRecursiveLevel()
    {
        if ($this->climate()->arguments->defined('recursive')) {
            return $this->climate()->arguments->get('recursive');
        }
        return 6;
    }
}
