<?php
/**
 * MIT License <https://opensource.org/licenses/mit>
 *
 * Copyright (c) 2015 Kerem Güneş
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is furnished
 * to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
declare(strict_types=1);

namespace froq\service;

use froq\App;
use froq\acl\Acl;
use froq\view\View;
use froq\config\Config;
use froq\session\Session;
use froq\validation\Validation;
use froq\util\traits\OneRunTrait;

/**
 * Service.
 * @package froq\service
 * @object  froq\service\Service
 * @author  Kerem Güneş <k-gun@mail.com>
 * @since   1.0
 */
abstract class Service
{
    /**
     * One run trait.
     * @object froq\util\traits\OneRunTrait
     */
    use OneRunTrait;

    /**
     * Namespace.
     * @const string
     */
    public const NAMESPACE             = 'froq\\app\\service';

    /**
     * Service types.
     * @const string
     */
    public const TYPE_SITE             = 'site',
                 TYPE_REST             = 'rest';

    /**
     * Service suffix and names.
     * @const string
     */
    public const SERVICE_NAME_SUFFIX   = 'Service',
                 SERVICE_MAIN          = 'Main',
                 SERVICE_FAIL          = 'Fail';

    /**
     * Service method prefix and names.
     * @const string
     */
    public const METHOD_NAME_PREFIX    = 'do',
                 METHOD_INIT           = 'init',
                 METHOD_MAIN           = 'main',
                 METHOD_FALL           = 'fall',
                 METHOD_ON_BEFORE      = 'onBefore',
                 METHOD_ON_AFTER       = 'onAfter';

    /**
     * App.
     * @var froq\App
     */
    protected $app;

    /**
     * Name.
     * @var string
     */
    protected $name;

    /**
     * Method.
     * @var string
     */
    protected $method;

    /**
     * Method arguments.
     * @var array
     */
    protected $methodArguments = [];

    /**
     * Config.
     * @var froq\config\Config
     */
    protected $config;

    /**
     * View.
     * @var froq\view\View
     */
    protected $view;

    /**
     * Session.
     * @var froq\session\Session
     */
    protected $session;

    /**
     * Model.
     * @var froq\database\model\Model
     */
    protected $model;

    /**
     * Validation.
     * @var froq\validation\Validation
     */
    protected $validation;

    /**
     * Acl.
     * @var froq\acl\Acl
     */
    protected $acl;

    /**
     * Use model.
     * @var bool
     */
    protected $useModel = false;

    /**
     * Use view.
     * @var bool
     */
    protected $useView = false;

    /**
     * Use view partials.
     * @var bool
     */
    protected $useViewPartials = false;

    /**
     * Use session.
     * @var bool
     */
    protected $useSession = false;

    /**
     * Use main only.
     * @var bool
     */
    protected $useMainOnly = false;

    /**
     * Allowed request methods.
     * @var ?array
     */
    protected $allowedRequestMethods = null;

    /**
     * Constructor.
     * @param froq\App|null $app
     * @param string|null   $name
     * @param string|null   $method
     * @param array|null    $methodArguments
     */
    public final function __construct(App $app = null, string $name = null, string $method = null,
        array $methodArguments = null)
    {
        // get app from global if null given (for internal calls)
        $app = $app ?? app();
        if ($app == null) {
            throw new ServiceException('Services need an instance of froq\App, no instance given to '.
                'constructor and found in global froq scope');
        }
        // get name as called class (for internal calls)
        $name = $name ?? substr(strrchr(static::class, '\\'), 1);

        $this->app = $app;
        $name && $this->setName($name);
        $method && $this->setMethod($method);
        $method && $methodArguments && $this->setMethodArguments($method, $methodArguments);

        // these methods work only with self config
        $this->loadConfig();
        $this->loadAcl();
        $this->loadValidation();
        if ($this->useModel) {
            $this->loadModel();
        }

        if ($this->useView) {
            $this->view = new View($this);
        }
        if ($this->useSession) {
            $this->session = Session::init($this->app->configValue('session'));
        }

        // call service init method
        $methodInit = self::METHOD_INIT;
        if (method_exists($this, $methodInit)) {
            $this->$methodInit();
        }
    }

    /**
     * Get app.
     * @return froq\App
     */
    public final function getApp(): App
    {
        return $this->app;
    }

    /**
     * Set name.
     * @param  string $name
     * @return self
     */
    public final function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name.
     * @return ?string
     */
    public final function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Set method.
     * @param  string $method
     * @return self
     */
    public final function setMethod(string $method): self
    {
        $this->method = $method;

        return $this;
    }

    /**
     * Get method.
     * @return ?string
     */
    public final function getMethod(): ?string
    {
        return $this->method;
    }

    /**
     * Set method arguments.
     * @param  string $method
     * @param  array  $methodArguments
     * @return self
     */
    public final function setMethodArguments(string $method, array $methodArguments): self
    {
        $this->methodArguments[$method] = $methodArguments;

        return $this;
    }

    /**
     * Get method arguments.
     * @param  string|null $method
     * @return ?array
     */
    public final function getMethodArguments(string $method = null): ?array
    {
        return $method ? $this->methodArguments[$method] ?? null : $this->methodArguments;
    }

    /**
     * Set allowed request methods.
     * @param  array $allowedRequestMethods
     * @return self
     */
    public final function setAllowedRequestMethods(array $allowedRequestMethods): self
    {
        $this->allowedRequestMethods = array_map('strtoupper', $allowedRequestMethods);

        return $this;
    }

    /**
     * Get allowed request methods.
     * @return ?array
     */
    public final function getAllowedRequestMethods(): ?array
    {
        return $this->allowedRequestMethods;
    }

    /**
     * Is allowed request method.
     * @param  string $method
     * @return bool
     */
    public final function isAllowedRequestMethod(string $method): bool
    {
        return $this->allowedRequestMethods == null || in_array($method, (array) $this->allowedRequestMethods);
    }

    /**
     * Get short name.
     * @return ?string
     */
    public final function getShortName(): ?string
    {
        return ($this->name != null) ? substr($this->name, 0, -strlen(self::SERVICE_NAME_SUFFIX)) : null;
    }

    /**
     * Get type.
     * @return string
     */
    public final function getType(): string
    {
        return $this->type;
    }

    /**
     * Get config.
     * @return froq\config\Config
     */
    public final function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Get view.
     * @return ?froq\view\View
     */
    public final function getView(): ?View
    {
        return $this->view;
    }

    /**
     * Get session.
     * @return ?froq\session\Session
     */
    public final function getSession(): ?Session
    {
        return $this->session;
    }

    /**
     * Get model.
     * @note   Model Not included in composer.json, so return type is not set here.
     * @return froq\database\model\Model
     */
    public final function getModel()
    {
        return $this->model;
    }

    /**
     * Get acl.
     * @return froq\acl\Acl
     */
    public final function getAcl(): Acl
    {
        return $this->acl;
    }

    /**
     * Get validation.
     * @return froq\validation\Validation
     */
    public final function getValidation(): Validation
    {
        return $this->validation;
    }

    /**
     * Is site.
     * @return bool
     */
    public final function isSite(): bool
    {
        return $this->type == self::TYPE_SITE;
    }

    /**
     * Is rest.
     * @return bool
     */
    public final function isRest(): bool
    {
        return $this->type == self::TYPE_REST;
    }

    /**
     * Is main service.
     * @return bool
     */
    public final function isMainService(): bool
    {
        return $this->name == (self::SERVICE_MAIN . self::SERVICE_NAME_SUFFIX);
    }

    /**
     * Is fail service.
     * @return bool
     */
    public final function isFailService(): bool
    {
        return $this->name == (self::SERVICE_FAIL . self::SERVICE_NAME_SUFFIX);
    }

    /**
     * Is default service.
     * @return bool
     */
    public final function isDefaultService(): bool
    {
        return $this->isMainService() || $this->isFailService();
    }

    /**
     * Uses model.
     * @return bool
     */
    public final function usesModel(): bool
    {
        return $this->useModel == true;
    }

    /**
     * Uses view.
     * @return bool
     */
    public final function usesView(): bool
    {
        return $this->useView == true;
    }

    /**
     * Uses view partials.
     * @return bool
     */
    public final function usesViewPartials(): bool
    {
        return $this->useViewPartials == true;
    }

    /**
     * Uses session.
     * @return bool
     */
    public final function usesSession(): bool
    {
        return $this->useSession == true;
    }

    /**
     * Uses main only.
     * @return bool
     */
    public final function usesMainOnly(): bool
    {
        return $this->useMainOnly == true;
    }

    /**
     * Run.
     * @return any That returned from service's target (called) method.
     * @throws froq\service\ServiceException
     */
    public final function run(bool $checkRun = true)
    {
        // run once
        if ($checkRun) {
            $this->___checkRun(new ServiceException("You cannot call {$this->name}::run() anymore, it's ".
                "already called in App::run() once"));
        }

        $request = $this->app->request();
        $response = $this->app->response();

        [$serviceMain, $methodMain, $methodOnBefore, $methodOnAfter] = [
            self::SERVICE_MAIN, self::METHOD_MAIN, self::METHOD_ON_BEFORE, self::METHOD_ON_AFTER];

        $serviceName = $request->uri()->segment(1);
        if ($serviceName != null && strtolower($serviceName) == strtolower($serviceMain)) {
            // redirect "main/" to "/" (301 Moved Permanently)
            return $response->redirect('/', 301)->end();
        }
        $serviceMethod = $request->uri()->segment(2);
        if ($serviceMethod != null && strtolower($serviceMethod) == $methodMain) {
            // redirect "<service>/main" to "<service>/" (301 Moved Permanently)
            return $response->redirect('/'. strtolower($serviceName), 301)->end();
        }

        // request method is allowed?
        if (!$this->isAllowedRequestMethod($request->method()->getName())) {
            $response->setStatus(405);
        }

        // call service onbefore
        if (method_exists($this, $methodOnBefore)) {
            $this->$methodOnBefore();
        }

        $output = null;

        // check site/rest, call the target method
        if ($this->isSite()) {
            $methodArguments = (array) $this->getMethodArguments($this->method);
            if ($this->method == '' || $this->method == $methodMain) {
                $output = $this->$methodMain($methodArguments);
            } elseif (method_exists($this, $this->method)) {
                $output = call_user_func_array([$this, $this->method], $methodArguments);
            } else {
                $output = $this->$methodMain($methodArguments); // calls FailService::main() actually
            }
        } elseif ($this->isRest()) {
            $methodArguments = (array) $this->getMethodArguments($this->method);
            if ($this->method == '' || $this->method == $methodMain) {
                $output = $this->$methodMain($methodArguments);
            } elseif (in_array($this->method, ['get', 'post', 'put', 'delete'])) {
                $output = call_user_func_array([$this, $this->method], $methodArguments);
            } else {
                $output = $this->$methodMain($methodArguments); // calls FailService::main() actually
            }
        }

        // call service onafter
        if (method_exists($this, $methodOnAfter)) {
            $this->$methodOnAfter();
        }

        return $output;
    }

    /**
     * View.
     * @param  string $file
     * @param  array  $content
     * @param  bool   $useViewPartials
     * @return void
     * @throws froq\service\ServiceException
     */
    public final function view(string $file, array $content = null, bool $useViewPartials = null): void
    {
        if (!$this->useView) {
            throw new ServiceException("Set \$useView = true in service and be sure that already ".
                "included 'froq/froq-view' module via Composer");
        }

        $data = (array) ($content['data'] ?? []);
        $meta = (array) ($content['meta'] ?? []);

        // set meta if provided
        if ($meta != null) {
            foreach ($meta as $name => $value) {
                $this->view->setMeta($name, $value);
            }
        }

        $this->view->setFile($file);

        // override on runtime calls
        $useViewPartials = $useViewPartials ?? $this->useViewPartials;

        // set header/footer partials if uses
        if ($useViewPartials) {
            $this->view->setFileHead();
            $this->view->setFileFoot();
        }

        $this->view->display($data);
    }

    /**
     * Load model.
     * @return void
     */
    public final function loadModel(): void
    {
        if ($this->model != null) {
            return;
        }

        $file = sprintf('%s/app/service/%s/model/model.php', APP_DIR, $this->name);
        if (!file_exists($file)) {
            throw new ServiceException("Cannot load {$this->name} model, model file app/service/{$this->name}".
                "/model/model.php not found");
        }

        // FooService => FooModel
        $class = sprintf('froq\\app\\database\\%sModel', substr($this->name, 0, -strlen(self::SERVICE_NAME_SUFFIX)));
        if (!class_exists($class)) {
            throw new ServiceException("Cannot load {$this->name} model, model class {$class} not found");
        }

        $this->model = new $class($this);
    }

    /**
     * Load config.
     * @return void
     */
    private final function loadConfig(): void
    {
        $this->config = new Config();

        $file = sprintf('%s/app/service/%s/config/config.php', APP_DIR, $this->name);
        if (file_exists($file) && is_array($data = include $file)) {
            $this->config->setData($data);
        }
    }

    /**
     * Load acl.
     * @return void
     */
    private final function loadAcl(): void
    {
        $this->acl = new Acl($this);

        $rules = $this->config->get('acl.rules');
        if ($rules != null) {
            $this->acl->setRules($rules);
        }
    }

    /**
     * Load validation.
     * @return void
     */
    private final function loadValidation(): void
    {
        $this->validation = new Validation();

        $rules = $this->config->get('validation.rules');
        if ($rules != null) {
            $this->validation->setRules($rules);
        }
    }
}
