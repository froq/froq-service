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

use Froq\Util\Traits\GetterTrait as Getter;
use Froq\App;
use Froq\Config\Config;
use Froq\View\View;

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
     * @object Froq\Util\Traits\Getter
     */
    use Getter;

    /**
     * Froq object.
     * @var Froq\App
     */
    protected $app;

    /**
     * Service name.
     * @var string
     */
    protected $name;

    /**
     * Service method.
     * @var string
     */
    protected $method;

    /**
     * Service method args.
     * @var array
     */
    protected $methodArgs = [];

    /**
     * Service model.
     * @var Froq\Database\Model\Model
     */
    protected $model;

    /**
     * Service view.
     * @var Froq\Util\View
     */
    protected $view;

    /**
     * Service config.
     * @var Froq\Util\Config
     */
    protected $config;

    /**
     * Call only main() method.
     * @var bool
     */
    protected $useMainOnly = false;

    /**
     * Use view.
     * @var bool
     */
    protected $useView = false;

    /**
     * Use head/foot files.
     * @var bool
     */
    protected $useViewPartials = false;

    /**
     * Validation.
     * @var Froq\Validation\Validation
     */
    protected $validation;

    /**
     * Validation rules (that could be overwriten calling validation::setRules() method after.)
     * @var array
     */
    protected $validationRules = [];

    /**
     * Use session.
     * @var bool
     */
    protected $useSession = false;

    /**
     * Request method limiter.
     * @var array
     */
    protected $allowedRequestMethods = [];

    /**
     * Constructor.
     *
     * @param Froq\App $app
     * @param string   $name
     * @param string   $method
     * @param array    $methodArgs
     */
    final public function __construct(App $app,
        string $name = null, string $method = null, array $methodArgs = null)
    {
        $this->app = $app;

        if ($name) $this->setName($name);
        if ($method) $this->setMethod($method);
        if ($methodArgs) $this->setMethodArgs($methodArgs);

        // load config & model
        $this->loadConfig(); $this->loadModel();

        // create view
        if ($this->useView) {
            $this->view = new View($this->app, null, $this->useViewPartials);
        }

        // create validation @out
        // if (empty($this->validationRules) && $this->config != null) {
        //     $this->validationRules = $this->config->get('validation.rules', []);
        // }
        // $this->validation = new Validation($this->validationRules);

        // prevent lowercased method names
        if (!empty($this->allowedRequestMethods)) {
            $this->allowedRequestMethods = array_map('strtoupper', $this->allowedRequestMethods);
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
     * Set method args.
     * @param  array $methodArgs
     * @return self
     */
    final public function setMethodArgs(array $methodArgs = []): self
    {
        $this->methodArgs = $methodArgs;

        return $this;
    }

    /**
     * Get method args.
     * @return array
     */
    final public function getMethodArgs(): array
    {
        return $this->methodArgs;
    }

    /**
     * Run and return called method's return.
     * @return any
     */
    final public function run()
    {
        // call init method
        if (method_exists($this, ServiceInterface::METHOD_INIT)) {
            $this->{ServiceInterface::METHOD_INIT}();
        }

        // call external onbefore
        $this->app->events->fire('service.methodBefore');

        // call internal onbefore
        if (method_exists($this, ServiceInterface::METHOD_ONBEFORE)) {
            $this->{ServiceInterface::METHOD_ONBEFORE}();
        }

        $output = null;
        // site interface
        if ($this->protocol == ServiceInterface::PROTOCOL_SITE) {
            // always uses main method?
            if ($this->useMainOnly || empty($this->method) || $this->method == ServiceInterface::METHOD_MAIN) {
                $output = $this->{ServiceInterface::METHOD_MAIN}();
            } elseif (method_exists($this, $this->method)) {
                $output = call_user_func_array([$this, $this->method], $this->methodArgs);
            } else {
                // call fail::main
                $output = $this->{ServiceInterface::METHOD_MAIN}();
            }
        }
        // rest interface
        elseif ($this->protocol == ServiceInterface::PROTOCOL_REST) {
            // always uses main method?
            if ($this->useMainOnly) {
                $output = $this->{ServiceInterface::METHOD_MAIN}();
            } elseif (method_exists($this, $this->method)) {
                $output = call_user_func_array([$this, $this->method], $this->methodArgs);
            } else {
                // call fail::main
                $output = $this->{ServiceInterface::METHOD_MAIN}();
            }
        }

        // call internal onafter
        if (method_exists($this, ServiceInterface::METHOD_ONAFTER)) {
            $this->{ServiceInterface::METHOD_ONAFTER}();
        }

        // call external onafter
        $this->app->events->fire('service.methodAfter');

        return $output;
    }

    /**
     * Set allowed request methods.
     * @param  string ...$allowedRequestMethods
     * @return self
     */
    final public function setAllowedRequestMethods(array ...$allowedRequestMethods): self
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
     * Check request method is allowed.
     * @return bool
     */
    final public function isAllowedRequestMethod(string $requestMethod): bool
    {
        if (empty($this->allowedRequestMethods)) {
            return true;
        }

        return in_array($requestMethod, $this->allowedRequestMethods);
    }

    /**
     * Load service sprecific configs.
     * @return self
     */
    final private function loadConfig(): self
    {
        $file = sprintf('./app/service/%s/config/config.php', $this->name);
        if (is_file($file)) {
            $this->config = new Config($file);
        }

        return $this;
    }

    /**
     * Load model.
     * @return self
     */
    final private function loadModel(): self
    {
        $file = sprintf('./app/service/%s/model/model.php', $this->name);
        if (is_file($file)) {
            require($file);
        }

        return $this;
    }

    /**
     * View support.
     * @param  string $file
     * @param  array  $data
     * @return void
     */
    final public function view(string $file, array $data = null)
    {
        if (!$this->useView || !$this->view) {
            throw new ServiceException(
                "Set service \$useView property as TRUE and be sure " .
                "that already included 'froq/froq-view' module via Composer."
            );

        }

        $this->view->setFile($file)->displayAll($data);
    }

    /**
     * Check is main service.
     * @return bool
     */
    final public function isMainService(): bool
    {
        return ($this->name == ServiceInterface::SERVICE_MAIN);
    }

    /**
     * Check is fail service.
     * @return bool
     */
    final public function isFailService(): bool
    {
        return ($this->name == ServiceInterface::SERVICE_FAIL);
    }

    /**
     * Check is default service (fail, main).
     * @return bool
     */
    final public function isDefaultService(): bool
    {
        return ($this->isMain() || $this->isFail());
    }

    /**
     * Check is REST protocol.
     * @return bool
     */
    final public function isRestProtocol(): bool
    {
        return ($this->protocol == ServiceInterface::PROTOCOL_SITE);
    }

    /**
     * Check is site protocol.
     * @return bool
     */
    final public function isSiteProtocol(): bool
    {
        return ($this->protocol == ServiceInterface::PROTOCOL_SITE);
    }

    /**
     * Check uses view.
     * @return bool
     */
    final public function usesView(): bool
    {
        return ($this->useView == true);
    }

    /**
     * Check uses session.
     * @return bool
     */
    final public function usesSession(): bool
    {
        return ($this->useSession == true);
    }

    /**
     * Check uses validation.
     * @return bool
     */
    final public function usesValidation(): bool
    {
        return ($this->validation && !empty($this->validationRules));
    }
}
