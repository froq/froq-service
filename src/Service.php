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

namespace Froq\Service;

use Froq\App;
use Froq\Acl\Acl;
use Froq\View\View;
use Froq\Config\Config;
use Froq\Session\Session;
use Froq\Validation\Validation;

/**
 * @package    Froq
 * @subpackage Froq\Service
 * @object     Froq\Service\Service
 * @author     Kerem Güneş <k-gun@mail.com>
 */
abstract class Service
{
    /**
     * Namespace.
     * @const string
     */
    public const NAMESPACE             = 'Froq\\App\\Service\\';

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
     * @var Froq\App
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
    protected $methodArguments;

    /**
     * Uri.
     * @var string
     */
    protected $uri;

    /**
     * Uri full.
     * @var string
     */
    protected $uriFull;

    /**
     * Config.
     * @var Froq\Config\Config
     */
    protected $config;

    /**
     * View.
     * @var Froq\View\View
     */
    protected $view;

    /**
     * Session.
     * @var Froq\Session\Session
     */
    protected $session;

    /**
     * Model.
     * @var Froq\Database\Model\Model
     */
    protected $model;

    /**
     * Validation.
     * @var Froq\Validation\Validation
     */
    protected $validation;

    /**
     * Acl.
     * @var Froq\Acl\Acl
     */
    protected $acl;

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
     * @var array
     */
    protected $allowedRequestMethods = [];

    /**
     * Constructor.
     * @param Froq\App $app
     * @param string   $name
     * @param string   $method
     * @param array    $methodArguments
     */
    public final function __construct(App $app, string $name = null, string $method = null,
        array $methodArguments = null)
    {
        $this->app = $app;

        if ($name != null) $this->setName($name);
        if ($method != null) $this->setMethod($method);
        if ($methodArguments != null) $this->setMethodArguments($methodArguments);

        // these methods work only with self config
        $this->loadConfig();
        $this->loadAcl();
        $this->loadValidation();

        if ($this->useView) {
            $this->view = new View($this);
        }

        if ($this->useSession) {
            $this->session = Session::init($this->app->configValue('session'));
        }
    }

    /**
     * Get app.
     * @return Froq\App
     */
    public final function getApp(): App
    {
        return $this->app;
    }

    /**
     * Set name.
     * @param  string $name
     * @return void
     */
    public final function setName(string $name): void
    {
        $this->name = $name;
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
     * @return void
     */
    public final function setMethod(string $method): void
    {
        $this->method = $method;
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
     * @param  array $methodArguments
     * @return void
     */
    public final function setMethodArguments(array $methodArguments): void
    {
        $this->methodArguments = $methodArguments;
    }

    /**
     * Get method arguments.
     * @return ?array
     */
    public final function getMethodArguments(): ?array
    {
        return $this->methodArguments;
    }

    /**
     * Get uri.
     * @return string
     */
    public final function getUri(): string
    {
        if ($this->uri == null) {
            // get real uri even if alias used
            $name = implode('-', array_slice(explode('-', to_dash_from_upper($this->name)), 0, -1));
            $this->uri = '/'. $name;

            if ($this->isSite()) {
                $method = implode('-', array_slice(explode('-', to_dash_from_upper($this->method)), 1));
                $this->uri .= '/'. $method;
            }
        }

        return rtrim($this->uri, '/');
    }

    /**
     * Get uri full.
     * @return string
     */
    public final function getUriFull(): string
    {
        if ($this->uriFull == null) {
            $this->uriFull = $this->getUri();

            $arguments = $this->app->request()->uri()->segmentArguments($this->isSite() ? 2 : 1);
            if (!empty($arguments)) {
                $this->uriFull = sprintf('%s/%s', $this->uriFull, implode('/', $arguments));
            }
        }

        return rtrim($this->uriFull, '/');
    }

    /**
     * Set allowed request methods.
     * @param  array $allowedRequestMethods
     * @return void
     */
    public final function setAllowedRequestMethods(array $allowedRequestMethods): void
    {
        $this->allowedRequestMethods = array_map('strtoupper', $allowedRequestMethods);
    }

    /**
     * Get allowed request methods.
     * @return array
     */
    public final function getAllowedRequestMethods(): array
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
        return empty($this->allowedRequestMethods) || in_array($method, $this->allowedRequestMethods);
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
     * @return Froq\Config\Config
     */
    public final function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Get view.
     * @return ?Froq\View\View
     */
    public final function getView(): ?View
    {
        return $this->view;
    }

    /**
     * Get session.
     * @return ?Froq\Session\Session
     */
    public final function getSession(): ?Session
    {
        return $this->session;
    }

    /**
     * Get model.
     * @return Froq\Database\Model\Model Not included in composer.json, so return type is not set here.
     */
    public final function getModel()
    {
        return $this->model;
    }

    /**
     * Get acl.
     * @return Froq\Acl\Acl
     */
    public final function getAcl(): Acl
    {
        return $this->acl;
    }

    /**
     * Get validation.
     * @return Froq\Validation\Validation
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
     * Uses view.
     * @return bool
     */
    public final function usesView(): bool
    {
        return $this->useView === true;
    }

    /**
     * Uses view partials.
     * @return bool
     */
    public final function usesViewPartials(): bool
    {
        return $this->useViewPartials === true;
    }

    /**
     * Uses session.
     * @return bool
     */
    public final function usesSession(): bool
    {
        return $this->useSession === true;
    }

    /**
     * Uses main only.
     * @return bool
     */
    public final function usesMainOnly(): bool
    {
        return $this->useMainOnly === true;
    }

    /**
     * Run.
     * @return any That returned from service's target (called) method.
     */
    public final function run()
    {
        $request = $this->app->request();
        $response = $this->app->response();

        [$methodMain, $methodInit, $methodOnBefore, $methodOnAfter] = [
            self::METHOD_MAIN, self::METHOD_INIT,
            self::METHOD_ON_BEFORE, self::METHOD_ON_AFTER,
        ];

        // redirect "service/main" to "service/" (301 Moved Permanently)
        @ [$serviceName, $serviceMethod] = $request->uri()->segments();
        if ($serviceMethod != null && strtolower($serviceMethod) == $methodMain) {
            $response->redirect('/'. strtolower($serviceName), 301);
            $response->end();
            return;
        }

        // call service init method
        if (method_exists($this, $methodInit)) {
            $this->$methodInit();
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

        // check site or rest interface, call the target method
        if ($this->isSite()) {
            if ($this->useMainOnly || $this->method == $methodMain || $this->method === '') {
                $output = $this->$methodMain();
            } elseif (method_exists($this, $this->method)) {
                $output = call_user_func_array([$this, $this->method], (array) $this->methodArguments);
            } else {
                $output = $this->$methodMain(); // calls FailService::main() actually
            }
        } elseif ($this->isRest()) {
            if ($this->useMainOnly) {
                $output = $this->$methodMain();
            } elseif (in_array($this->method, ['get', 'post', 'put', 'delete'])) {
                $output = call_user_func_array([$this, $this->method], (array) $this->methodArguments);
            } else {
                $output = $this->$methodMain(); // calls FailService::main() actually
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
        if (!empty($meta)) {
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

        $this->view->displayAll($data);
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
        if (!empty($rules)) {
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
        if (!empty($rules)) {
            $this->validation->setRules($rules);
        }
    }
}
