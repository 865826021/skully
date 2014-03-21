<?php


namespace Skully;

use Skully\Core\ConfigInterface;
use Skully\Core\ControllerInterface;
use Skully\Core\RouterInterface;
use Skully\Core\Router;
use Skully\Core\ControllerFactory;
use Skully\Core\Theme\Theme;
use Skully\Core\Theme\ThemeInterface;
use Skully\Core\TranslatorInterface;
use Skully\Core\Translator;
use Skully\Logging\LoggerInterface;
use Skully\Logging\Logger;
use Skully\Exceptions\InvalidConfigException;
use Skully\Core\Templating\SmartyAdapter;
use Skully\Exceptions\PageNotFoundException;
use Skully\Core\RepositoryInterface;
use Skully\Core\RepositoryFactory;
use Skully\Core\Http;
use Skully\App\Session\DBSession;

use RedBean_Facade as R;
use RedBean_ModelHelper;
use RedBean_DependencyInjector;


/**
 * Class Application
 * @package Skully
 * Dependency Injection Container of entire Skully application
 * Refer to ApplicationInterface for documentation
 */
class Application implements ApplicationInterface {
    /** @var \Skully\App\Session\DBSession */
    protected $session;

    protected $modelsInjector;

    /**
     * @var null
     */
    protected static $instance = null;

    /**
     * @var ConfigInterface
     */
    protected $config;

    /**
     * @var RouterInterface
     */
    protected $router;

    /**
     * @var ControllerInterface[]
     */
    protected $controllers = array();

    /**
     * @var RepositoryInterface[]
     */
    protected $repositories = array();

    /**
     * @var array
     */
    protected $currentController = array();


    /**
     * @var ThemeInterface
     */
    protected $theme;

    /**
     * @var SmartyAdapter
     */
    protected $templateEngine;

    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var Http
     */
    protected $http;

    /**
     * @param ConfigInterface $config
     * @throws InvalidConfigException
     */
    public function __construct(ConfigInterface $config) {
        $this->setConfig($config);
        if ($this->configIsEmpty('timezone')) {
            $this->getConfigObject()->setProtected('timezone', 'Asia/Jakarta');
        }
        date_default_timezone_set($this->config('timezone'));
        $this->addLangfile('common', $this->config('language'));
        $this->addLangfile('common', $this->getLanguage());

        // Setting up RedBean
        $dbConfig = $config->getProtected('dbConfig');
        if (!empty($dbConfig)) {
            if ($dbConfig['type'] == 'mysql') {
                self::setupRedBean("mysql:host={$dbConfig['host']};dbname={$dbConfig['dbname']};port={$dbConfig['port']}", $dbConfig['user'], $dbConfig['password'], $config->getProtected('isDevMode'));
            }
            elseif ($dbConfig['type'] == 'sqlite') {
                self::setupRedBean("sqlite:{$dbConfig['dbname']}", $dbConfig['user'], $dbConfig['password'], $config->getProtected('isDevMode'));
            }
            // Below is needed so that RedBean_SimpleModel may use $this->app:
            $this->modelsInjector = new RedBean_DependencyInjector;
            RedBean_ModelHelper::setDependencyInjector( $this->modelsInjector );
            $this->modelsInjector->addDependency('App', $this);
            // Add Settings to config
            $this->mergeSettingsToConfig();
        }
    }

    /**
     * Merge settings to config.
     */
    protected function mergeSettingsToConfig() {
        $settings = R::findAll('setting');
        if (!empty($settings)) {
            foreach($settings as $setting) {
                $value = $setting->value;
                settype($value, $setting->type);
                if ($setting->is_client) {
                    $this->config->setPublic($setting->name, $value);
                }
                else {
                    $this->config->setProtected($setting->name, $value);
                }
            }
        }
    }

    /**
     * @param string $dsn
     * @param $user
     * @param string $password
     * @param bool $isDevMode
     */
    public static function setupRedBean($dsn, $user, $password = '', $isDevMode = false)
    {
        $formatter = new ModelFormatter;
        RedBean_ModelHelper::setModelFormatter($formatter);
        if (!isset( R::$toolboxes['default'] )) {
            R::setup($dsn, $user,$password, !$isDevMode);
        }

    }

    /**
     * Creates a model
     * @param $name
     * @param array $attributes
     * @return \Skully\App\Models\BaseModel
     * @throws \Exception
     */
    public function createModel($name, $attributes = array())
    {
        /** @var \RedBean_SimpleModel $bean */
        $bean = R::dispense(strtolower($name));
        /** @var \Skully\App\Models\BaseModel $model */
        $model = $bean->box();
        if (empty($model)) {
            throw new \Exception("Model ".ucfirst($name)." not found.");
        }
        if (!empty($attributes)) {
            foreach ($attributes as $attribute => $value) {
                $model->set($attribute, $value);
            }

        }
        return $model;
    }

    public function getSession()
    {
        if (empty($this->session)) {
            $this->session = new DBSession($this);
        }
        return $this->session;
    }

    /**
     * @param ConfigInterface $config
     * @return void
     * Set the whole config object
     */
    public function setConfig(ConfigInterface $config)
    {
        $this->config = $config;
    }

    /**
     * @param $key
     * @return mixed
     * Get both protected and public configs.
     */
    public function config($key = null)
    {
        $value = $this->config->get($key);
        if ($key == 'basePath') {
            if (substr($value,-1, 1) !== DIRECTORY_SEPARATOR) {
                $value = $value.DIRECTORY_SEPARATOR;
            }
        }
        return $value;
    }

    /**
     * @param $key
     * @return bool
     * Check if config is empty, since we cannot use !empty(config($key))
     */
    public function configIsEmpty($key) {
        $config = $this->config($key);
        return empty($config);
    }


    /**
     * @param null $key
     * @return mixed
     * "Safe" config(s) to be displayed in client.
     */
    public function clientConfig($key = null)
    {
        return $this->config->getPublic($key);
    }

    /**
     * @return ConfigInterface
     * Get the whole config object.
     */
    public function getConfigObject() {
        return $this->config;
    }


    /**
     * @param $rawUrl
     * @param array $additionalParams
     * @return null|\Skully\Core\ControllerInterface
     * @throws PageNotFoundException
     */
    public function runControllerFromRawUrl($rawUrl, $additionalParams = array()) {
        try {
            $routeAndParams = $this->getRouter()->rawUrlToRouteAndParams($rawUrl);
            $controllerAndAction = $this->getRouter()->routeToControllerAndAction($routeAndParams['route']);
            $controller = $this->getController($controllerAndAction['controller'], $controllerAndAction['action'], $routeAndParams['params']);
            if (!empty($controller)) {
                $controller->setParams($additionalParams);
                $controller->setParams($_GET);

                $phpinputs = json_decode(file_get_contents( "php://input" ));
                $controller->setParams($phpinputs);

                $controller->setParams($_POST);

                // Run controller's currentAction
                $controller->runCurrentAction();
                return $controller;
            }
        }
        catch (PageNotFoundException $e) {
            $viewPath_r = explode('.', $e->getUrl());
            $extension = $viewPath_r[count($viewPath_r)-1];
            if (in_array($extension, array('jpg', 'jpeg', 'gif', 'png', 'csv', 'bmp', 'ico', 'zip', 'tar.gz'))) {
                $this->getLogger()->log('Resource not found: '.$rawUrl, 'warn');
            }
            else {
                throw $e;
            }
        }
        return null;
    }


    /**
     * @return string|null
     * @throws InvalidConfigException
     * Get current xmlLang based on session.
     * Setup session from get parameter when needed
     */
    public function getXmlLang() {
        if ($this->configIsEmpty('languages')) {
            throw new InvalidConfigException('Config must have option "languages" (example value: array( \'en\' => array(\'value\' => \'english\', \'code\' => \'en_us\') ))');
        }
        $languages = $this->config('languages');
        $lang = $this->getLanguage();

        foreach($languages as $language){
            if($language["value"] == $lang) return $language["code"];
        }
        return null;
    }

    /**
     * @return bool
     * Was the request made via ajax?
     */
    public function isAjax(){
        return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    }

    /**
     * @param $controllerStr
     * @param null $actionStr
     * @param array $additionalParams
     * @return mixed|null|ControllerInterface
     */
    public function getController($controllerStr, $actionStr = null, $additionalParams = array())
    {
        $controller = null;
        if (!empty($this->controllers[$controllerStr])) {
            /* @var $controller ControllerInterface */
            $controller = $this->controllers[$controllerStr];
        }
        else {
            $controller = ControllerFactory::create($this, $controllerStr, $actionStr, $additionalParams);
            $this->controllers[$controllerStr] = $controller;
        }


        if (!empty($controller)) {
            $controller->resetParams();
            if (!empty($additionalParams)) {
                $controller->setParams($additionalParams);
            }
            if (!is_null($actionStr)) {
                $controller->setCurrentAction($actionStr);
            }
        }
        return $controller;
    }

    /**
     * @param $repositoryStr
     * @return mixed|null|RepositoryInterface
     */
    public function getRepository($repositoryStr)
    {
        $repository = null;
        if (!empty($this->repositories[$repositoryStr])) {
            /* @var $repository RepositoryInterface */
            $repository = $this->repositories[$repositoryStr];
        }
        else {
            $repository = RepositoryFactory::create($this, $repositoryStr);
            $this->repositories[$repositoryStr] = $repository;
        }
        return $repository;
    }

    /**
     * @return array|mixed
     * Get current language based on session.
     * Setup session from get parameter when needed
     */
    public function getLanguage()
    {
        if (!empty($_GET['_language'])) {
            $_SESSION['__language'] = $_GET['_language'];
        }
        if (empty($_SESSION['__language'])) {
            $_SESSION['__language'] = $this->config('language');
        }
        return $_SESSION['__language'];
    }

    /**
     * @param TranslatorInterface $translator
     * @return void
     */
    public function setTranslator(TranslatorInterface $translator)
    {
        $this->translator = $translator;
    }

    /**
     * @return \Skully\Core\TranslatorInterface
     */
    public function getTranslator()
    {
        if (empty($this->translator)) {
            $this->setTranslator(new Translator($this->getLanguage()));
        }
        return $this->translator;
    }

    /**
     * @param $path
     * @param $language
     * Add language file into translator object. $path is relative to
     * 'public/[theme]/[App]/languages/[language]/' directory where [theme]
     * loads from default one first.
     */
    public function addLangfile($path, $language)
    {
        $this->addSkullyLangFile($path,$language);
        $this->addDefaultLangfile($path,$language);
        $this->addThemeLangfile($path,$language);
    }

    /**
     * @param $path
     * @param $language
     */
    protected function addSkullyLangfile($path, $language)
    {
        $completePath = $this->getSkullyBasePath().'public'.DIRECTORY_SEPARATOR.'default'.DIRECTORY_SEPARATOR.'Skully'.DIRECTORY_SEPARATOR.'languages'.DIRECTORY_SEPARATOR.$language.DIRECTORY_SEPARATOR.$path.'Lang.json';
        $this->innerAddLangFile($completePath);
    }

    /**
     * @param $path
     * @param $language
     */
    protected function addDefaultLangfile($path, $language)
    {
        $completePath = $this->config('basePath').'public'.DIRECTORY_SEPARATOR.'default'.DIRECTORY_SEPARATOR.$this->getAppName().DIRECTORY_SEPARATOR.'languages'.DIRECTORY_SEPARATOR.$language.DIRECTORY_SEPARATOR.$path.'Lang.json';
        $this->innerAddLangFile($completePath);
    }

    /**
     * @param $path
     * @param $language
     */
    protected function addThemeLangFile($path, $language)
    {
        $completePath = $this->getTheme()->getAppPath('languages'.DIRECTORY_SEPARATOR.$language.DIRECTORY_SEPARATOR.$path.'Lang.json', true);
        $this->innerAddLangFile($completePath);
    }

    /**
     * @param $completePath
     */
    protected function innerAddLangFile($completePath)
    {
        if (file_exists($completePath)) {
            $additionalLang = file_get_contents($completePath,true);
            if (!empty($additionalLang)) {
                // commonLang should already be added to translator object on app init.

                $additionalLang_r = json_decode($additionalLang, true);
                if (is_array($additionalLang_r)) {
                    $this->getTranslator()->addTranslations($additionalLang_r);
                }
                else {
                    $this->getLogger()->log('Invalid language file: '. $completePath);
                }
            }
        }
    }

    /**
     * @param $url
     * @param array $additionalParams
     * @return mixed|null|ControllerInterface
     * Get controller directly from raw urls (e.g. 'products/12?cat=wow')
     */
    public function getControllerFromRawUrl($url, $additionalParams = array()) {
        $routeAndParams = $this->getRouter()->rawUrlToRouteAndParams($url);
        $controllerAndAction = $this->getRouter()->RouteToControllerAndAction($routeAndParams['route']);
        $params=  array_merge($routeAndParams['params'], $additionalParams);
        return $this->getController($controllerAndAction['controller'], $controllerAndAction['action'], $params);
    }

    /**
     * @param RouterInterface $router
     */
    public function setRouter(RouterInterface $router) {
        $this->router = $router;
    }

    /**
     * @return Router
     * @throws Exceptions\InvalidConfigException
     */
    public function getRouter() {
        if (empty($this->router)) {
            $basePath = $this->config('basePath');
            if (empty($basePath)) {
                throw new InvalidConfigException('Config must have option "basePath"');
            }

            $baseUrl = $this->config('baseUrl');
            if (empty($baseUrl)) {
                throw new InvalidConfigException('Config must have option "baseUrl"');
            }

            $urlRules = $this->config('urlRules');
            if (empty($urlRules)) {
                throw new InvalidConfigException('Config must have option "urlRules"');
            }
            $this->setRouter(new Router($basePath, $baseUrl, $urlRules));
        }
        return $this->router;
    }

    /**
     * @param \Skully\Core\Theme\ThemeInterface $theme
     * @return void
     */
    public function setTheme($theme)
    {
        $this->theme = $theme;
    }

    /**
     * @return \Skully\Core\Theme\ThemeInterface
     * @throws Exceptions\InvalidConfigException
     */
    public function getTheme()
    {
        if (empty($this->theme)) {
            $basePath = $this->config('basePath');
            if (empty($basePath)) {
                throw new InvalidConfigException('Config must have option "basePath"');
            }
            $theme = $this->config('theme');
            if (empty($theme)) {
                $this->getConfigObject()->setProtected('theme', 'default');
                $theme = 'default';
            }
            $this->setTheme(new Theme($basePath, $this->config('baseUrl'), 'public', $theme, $this->getAppName()));
        }
        return $this->theme;
    }


    /**
     * @return mixed
     * Get Application's name
     */
    public function getAppName()
    {
        $class = get_called_class();
        $namespace_r = explode('\\',$class);
        return $namespace_r[0];
    }

    /**
     * @return SmartyAdapter
     */
    public function getTemplateEngine()
    {
        if (empty($this->templateEngine)) {
            $caching = 0;
            // Smarty caching is currently broken (v.3.1.16) so we disable it
//            if (!$this->configIsEmpty('caching')) {
//                $caching = $this->config('caching');
//            }
            $this->templateEngine = new SmartyAdapter($this->config('basePath'), $this->config('theme'), $this, $this->additionalTemplateEnginePluginsDir(), $caching);
            $this->templateEngine->registerObject('app', $this);
        }
        return $this->templateEngine;
    }

    /**
     * @param \Skully\Logging\LoggerInterface $logger
     */
    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    /**
     * @return \Skully\Logging\LoggerInterface
     */
    public function getLogger()
    {
        if (empty($this->logger)) {
            $this->setLogger(new Logger($this->config('basePath')));
        }
        return $this->logger;
    }

    /**
     * @return Http
     */
    public function getHttp()
    {
        if (empty($this->http)) {
            $this->setHttp(new Http());
        }
        return $this->http;
    }

    /**
     * @param $http
     */
    public function setHttp($http) {
        $this->http = $http;
    }

    /**
     * Redirect to path within application
     * @param string $path
     * @param array $params
     * @return null
     */
    public function redirect($path='', $params=array()) {
        return $this->getHttp()->redirect($this->getRouter()->getUrl($path, $params));
    }

    /**
     * Redirect to url outside application
     * @param string $url
     * @return null
     */
    public function aRedirect($url) {
        return $this->getHttp()->redirect($url);
    }

    /**
     * @return string
     */
    protected function getSkullyBasePath()
    {
        return realpath(dirname(__FILE__).'/../').DIRECTORY_SEPARATOR;
    }

    /**
     * @return array
     * Return additional pluginsDir used in template engine.
     * Override this as needed in the app.
     */
    protected function additionalTemplateEnginePluginsDir()
    {
        return array($this->config('basePath').$this->getAppName().'/smarty/plugins/');
    }

    /**
     * Get value from php.ini file, or from 'ini' config if server does not allow it.
     * @param $var
     * @return mixed
     */
    public function iniGet($var)
    {
        try {
            return ini_get($var);
        }
        catch (\Exception $e) {
            if ($this->configIsEmpty('ini') || empty($this->config()[$var])) {
                $this->getLogger()->log("Server does not allow ini_get, and ini config \"$var\" is not found. Please add 'ini' => {'$var' => 'value'} to your configurations.");
            }
            else {
                return $this->config('ini')[$var];
            }
        }
        return null;
    }
}