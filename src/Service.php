<?php
/**
 * Copyright (c) 2016 Kerem Güneş
 *     <k-gun@mail.com>
 *
 * GNU General Public License v3.0
 *     <http://www.gnu.org/licenses/gpl-3.0.txt>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
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
                 METHOD_ONBEFORE       = 'onBefore',
                 METHOD_ONAFTER        = 'onAfter';

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
    protected $methodArguments = [];

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

        $name && $this->setName($name);
        $method && $this->setMethod($method);
        $methodArguments && $this->setMethodArguments($methodArguments);

        $this->loadConfig();
        // these work with self.config
        $this->loadAcl();
        $this->loadValidation();

        if ($this->useView) {
            $this->view = new View($this);
        }
        if ($this->useSession) {
            $this->session = Session::init($this->app->configValue('app.session.cookie'));
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
     * @param  array $methodArguments
     * @return self
     */
    public final function setMethodArguments(array $methodArguments = []): self
    {
        $this->methodArguments = $methodArguments;

        return $this;
    }

    /**
     * Get method arguments.
     * @return array
     */
    public final function getMethodArguments(): array
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

            if ($this->type == self::TYPE_SITE) {
                $method = implode('-', array_slice(explode('-', to_dash_from_upper($this->method)), 1));
                $this->uri .= '/'. $method;
            }
        }

        return $this->uri;
    }

    /**
     * Get uri full.
     * @return string
     */
    public final function getUriFull(): string
    {
        if ($this->uriFull == null) {
            $this->uriFull = $this->getUri();

            $methodArguments = $this->app->request()->uri()->segmentArguments(
                $this->type == self::TYPE_SITE ? 2 : 1
            );
            if (!empty($methodArguments)) {
                $this->uriFull = sprintf('%s/%s', $this->uriFull, implode('/', $methodArguments));
            }
        }

        return $this->uriFull;
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
    public function usesMainOnly(): bool
    {
        return $this->useMainOnly == true;
    }

    /**
     * Run.
     * @return any That returned from service's target (called) method.
     */
    public final function run()
    {
        $output = null;

        // call service init method
        if (method_exists($this, self::METHOD_INIT)) {
            $this->{self::METHOD_INIT}();
        }

        // request method is allowed?
        if (!$this->isAllowedRequestMethod($this->app->request()->method()->getName())) {
            $this->app->response()->setStatus(405);
        }

        // call service onbefore
        if (method_exists($this, self::METHOD_ONBEFORE)) {
            $this->{self::METHOD_ONBEFORE}();
        }

        // check site or rest interface, call target method
        if ($this->type == self::TYPE_SITE) {
            if ($this->useMainOnly || empty($this->method) || $this->method == self::METHOD_MAIN) {
                $output = $this->{self::METHOD_MAIN}();
            } elseif (method_exists($this, $this->method)) {
                $output = call_user_func_array([$this, $this->method], $this->methodArguments);
            } else {
                // call FailService::main()
                $output = $this->{self::METHOD_MAIN}();
            }
        } elseif ($this->type == self::TYPE_REST) {
            if ($this->useMainOnly) {
                $output = $this->{self::METHOD_MAIN}();
            } elseif (method_exists($this, $this->method)) {
                $output = call_user_func_array([$this, $this->method], $this->methodArguments);
            } else {
                // call FailService::main()
                $output = $this->{self::METHOD_MAIN}();
            }
        }

        // call service onafter
        if (method_exists($this, self::METHOD_ONAFTER)) {
            $this->{self::METHOD_ONAFTER}();
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
            throw new ServiceException(
                "Set service \$useView property as 'true' and be sure " .
                "that already included 'froq/froq-view' module via Composer."
            );
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
        if (is_file($file) && is_array($data = include($file))) {
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
