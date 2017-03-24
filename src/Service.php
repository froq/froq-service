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
use Froq\Util\Traits\GetterTrait;

/**
 * @package    Froq
 * @subpackage Froq\Service
 * @object     Froq\Service\Service
 * @author     Kerem Güneş <k-gun@mail.com>
 */
abstract class Service implements ServiceInterface
{
    /**
     * Getter.
     * @object Froq\Util\Traits\GetterTrait
     */
    use GetterTrait;

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
     * URI.
     * @var string
     */
    protected $uri;

    /**
     * URI full.
     * @var string
     */
    protected $uriFull;

    /**
     * Config.
     * @var Froq\Util\Config
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
     * ACL.
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
     *
     * @param Froq\App $app
     * @param string   $name
     * @param string   $method
     * @param array    $methodArguments
     */
    final public function __construct(App $app, string $name = null, string $method = null,
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
            $this->session = Session::init($app->config['app.session.cookie']);
        }
    }

    /**
     * Set name.
     * @param  string $name
     * @return self
     */
    final public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name.
     * @return string
     */
    final public function getName(): string
    {
        return $this->name;
    }

    /**
     * Set method.
     * @param  string $method
     * @return self
     */
    final public function setMethod(string $method): self
    {
        $this->method = $method;

        return $this;
    }

    /**
     * Get method.
     * @return string
     */
    final public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Set method arguments.
     * @param  array $methodArguments
     * @return self
     */
    final public function setMethodArguments(array $methodArguments = []): self
    {
        $this->methodArguments = $methodArguments;

        return $this;
    }

    /**
     * Get method arguments.
     * @return array
     */
    final public function getMethodArguments(): array
    {
        return $this->methodArguments;
    }

    /**
     * Get URI.
     * @return string
     */
    final public function getUri(): string
    {
        if (!$this->uri) {
            // get real uri even if alias used
            $name = implode('-', array_slice(explode('-', to_dash_from_upper($this->name)), 0, -1));
            $method = implode('-', array_slice(explode('-', to_dash_from_upper($this->method)), 1));

            $this->uri = sprintf('/%s/%s', $name, $method);
        }

        return $this->uri;
    }

    /**
     * Get URI full.
     * @return string
     */
    final public function getUriFull(): string
    {
        if (!$this->uriFull) {
            $this->uriFull = $this->getUri();

            $methodArguments = $this->app->request->uri->segmentArguments();
            if (!empty($methodArguments)) {
                $this->uriFull = sprintf('%s/%s', $this->uriFull, implode('/', $methodArguments));
            }
        }

        return $this->uriFull;
    }

    /**
     * Run.
     * @return any That returned from service's target method.
     */
    final public function run()
    {
        $output = null;

        // call service init method
        if (method_exists($this, ServiceInterface::METHOD_INIT)) {
            $this->{ServiceInterface::METHOD_INIT}();
        }

        // request method is allowed?
        if (!$this->isAllowedRequestMethod($this->app->request->method->getName())) {
            $this->app->response->setStatus(405);
            $this->app->response->setContentType('none');

            return $output;
        }

        // call service onbefore @internal
        if (method_exists($this, ServiceInterface::METHOD_ONBEFORE)) {
            $this->{ServiceInterface::METHOD_ONBEFORE}();
        }

        // check site or rest interface, call target method
        if ($this->protocol == ServiceInterface::PROTOCOL_SITE) {
            if ($this->useMainOnly || empty($this->method) || $this->method == ServiceInterface::METHOD_MAIN) {
                $output = $this->{ServiceInterface::METHOD_MAIN}();
            } elseif (method_exists($this, $this->method)) {
                $output = call_user_func_array([$this, $this->method], $this->methodArguments);
            } else {
                // call fail::main
                $output = $this->{ServiceInterface::METHOD_MAIN}();
            }
        } elseif ($this->protocol == ServiceInterface::PROTOCOL_REST) {
            if ($this->useMainOnly) {
                $output = $this->{ServiceInterface::METHOD_MAIN}();
            } elseif (method_exists($this, $this->method)) {
                $output = call_user_func_array([$this, $this->method], $this->methodArguments);
            } else {
                // call fail::main
                $output = $this->{ServiceInterface::METHOD_MAIN}();
            }
        }

        // call service onafter @internal
        if (method_exists($this, ServiceInterface::METHOD_ONAFTER)) {
            $this->{ServiceInterface::METHOD_ONAFTER}();
        }

        return $output;
    }

    /**
     * Set allowed request methods.
     * @param  array $allowedRequestMethods
     * @return self
     */
    final public function setAllowedRequestMethods(array $allowedRequestMethods): self
    {
        $this->allowedRequestMethods = array_map('strtoupper', $allowedRequestMethods);

        return $this;
    }

    /**
     * Get allowed request methods.
     * @return array
     */
    final public function getAllowedRequestMethods(): array
    {
        return $this->allowedRequestMethods;
    }

    /**
     * Is allowed method.
     * @param  string $method
     * @return bool
     */
    final public function isAllowedRequestMethod(string $method): bool
    {
        return empty($this->allowedRequestMethods) || in_array($method, $this->allowedRequestMethods);
    }

    /**
     * View.
     * @param  string $file
     * @param  array  $data
     * @return void
     */
    final public function view(string $file, array $data = null, array $metas = null)
    {
        if (!$this->useView || !$this->view) {
            throw new ServiceException(
                "Set service \$useView property as 'true' and be sure " .
                "that already included 'froq/froq-view' module via Composer."
            );
        }

        // set metas if provided
        if ($metas) foreach ($metas as $name => $value) {
            $this->view->setMeta($name, $value);
        }

        $this->view->setFile($file);
        // set header/footer partials if uses
        if ($this->useViewPartials) {
            $this->view->setFileHead();
            $this->view->setFileFoot();
        }

        $this->view->displayAll($data);
    }

    /**
     * Is main service.
     * @return bool
     */
    final public function isMainService(): bool
    {
        return $this->name == ServiceInterface::SERVICE_MAIN . ServiceInterface::SERVICE_NAME_SUFFIX;
    }

    /**
     * Is fail service.
     * @return bool
     */
    final public function isFailService(): bool
    {
        return $this->name == ServiceInterface::SERVICE_FAIL . ServiceInterface::SERVICE_NAME_SUFFIX;
    }

    /**
     * Is default service.
     * @return bool
     */
    final public function isDefaultService(): bool
    {
        return $this->isMainService() || $this->isFailService();
    }

    /**
     * Is site protocol.
     * @return bool
     */
    final public function isSiteProtocol(): bool
    {
        return $this->protocol == ServiceInterface::PROTOCOL_SITE;
    }

    /**
     * Is REST protocol.
     * @return bool
     */
    final public function isRestProtocol(): bool
    {
        return $this->protocol == ServiceInterface::PROTOCOL_SITE;
    }

    /**
     * Uses view.
     * @return bool
     */
    final public function usesView(): bool
    {
        return ($this->useView == true);
    }

    /**
     * Uses view partials.
     * @return bool
     */
    final public function usesViewPartials(): bool
    {
        return ($this->useViewPartials == true);
    }

    /**
     * Uses session.
     * @return bool
     */
    final public function usesSession(): bool
    {
        return ($this->useSession == true);
    }

    /**
     * Load config.
     * @return void
     */
    final private function loadConfig()
    {
        $this->config = new Config();

        $file = sprintf('./app/service/%s/config/config.php', $this->name);
        if (is_file($file) && is_array($data = include($file))) {
            $this->config->setData($data);
        }

        return $this;
    }

    /**
     * Load ACL.
     * @return void
     */
    final private function loadAcl()
    {
        $this->acl = new Acl($this);

        $rules = $this->config->get('acl.rules');
        if (!empty($rules)) {
            $this->acl->setRules($rules);
        }

        return $this;
    }

    /**
     * Load validation.
     * @return void
     */
    final private function loadValidation()
    {
        $this->validation = new Validation();

        $rules = $this->config->get('validation.rules');
        if (!empty($rules)) {
            $this->validation->setRules($rules);
        }

        return $this;
    }
}
