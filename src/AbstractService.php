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

use froq\app\App;
use froq\view\View;
use froq\database\model\ModelInterface;
use froq\service\{ServiceInterface, ServiceException, RestService, SiteService};

/**
 * Abstract Service.
 * @package froq\service
 * @object  froq\service\AbstractService
 * @author  Kerem Güneş <k-gun@mail.com>
 * @since   1.0
 */
abstract class AbstractService
{
    /**
     * App.
     * @var froq\app\App
     */
    protected App $app;

    /**
     * Name.
     * @var string
     */
    protected string $name;

    /**
     * Method.
     * @var string
     */
    protected string $method;

    /**
     * Method arguments.
     * @var array
     */
    protected array $methodArguments;

    /**
     * View.
     * @var froq\view\View
     */
    protected View $view;

    /**
     * Model.
     * @var froq\database\model\ModelInterface
     */
    protected ModelInterface $model;

    /**
     * Use view.
     * @var bool
     */
    protected bool $useView = false;

    /**
     * Use model.
     * @var bool
     */
    protected bool $useModel = false;

    /**
     * Use session.
     * @var bool
     */
    protected bool $useSession = false;

    /**
     * Use main only.
     * @var bool
     */
    protected bool $useMainOnly = false;

    /**
     * Constructor.
     * @param froq\app\App $app
     * @param string|null  $name
     * @param string|null  $method
     * @param array|null   $methodArguments
     */
    public final function __construct(App $app, string $name = null, string $method = null,
        array $methodArguments = null)
    {
        $this->app = $app;

        // Given name or inited class name (for direct inits, eg: new FooService()).
        $this->name = $name ?? substr(strrchr(static::class, '\\'), 1);

        if ($method != null) {
            $this->setMethod($method);
            if ($methodArguments != null) {
                $this->setMethodArguments($method, $methodArguments);
            }
        }

        // These can be defined (overridden) in child class, defaults are false.
        if ($this->useView) $this->loadView();
        if ($this->useModel) $this->loadModel();
        if ($this->useSession) {
            $session = $app->session();
            if ($session == null) {
                throw new ServiceException('App has no session (check session option in config '.
                    ' and be sure it is not null)');
            }
            $session->start();
        }

        // Call service init method if defined in child class.
        $methodInit = ServiceInterface::METHOD_INIT;
        if (method_exists($this, $methodInit)) {
            $this->$methodInit();
        }
    }

    /**
     * Get app.
     * @return froq\app\App
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
     * @return string
     */
    public final function getName(): string
    {
        return $this->name;
    }

    /**
     * Get short name.
     * @return string
     */
    public final function getShortName(): string
    {
        return substr($this->name, 0, -strlen(ServiceInterface::SERVICE_NAME_SUFFIX));
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
        return $this->method ?? null;
    }

    /**
     * Set method arguments.
     * @param  string     $method
     * @param  array<any> $methodArguments
     * @return void
     */
    public final function setMethodArguments(string $method, array $methodArguments): void
    {
        $this->methodArguments[$method] = $methodArguments;
    }

    /**
     * Get method arguments.
     * @param  string|null $method
     * @return ?array<any>
     */
    public final function getMethodArguments(string $method = null): ?array
    {
        return $method ? $this->methodArguments[$method] ?? null
                       : $this->methodArguments ?? null;
    }

    /**
     * Get view.
     * @return ?froq\view\View
     */
    public final function getView(): ?View
    {
        return $this->view ?? null;
    }

    /**
     * Get model.
     * @return ?froq\database\model\ModelInterface
     */
    public final function getModel(): ?ModelInterface
    {
        return $this->model ?? null;
    }

    /**
     * Uses view.
     * @return bool
     */
    public final function usesView(): bool
    {
        return $this->useView;
    }

    /**
     * Uses model.
     * @return bool
     */
    public final function usesModel(): bool
    {
        return $this->useModel;
    }

    /**
     * Uses session.
     * @return bool
     */
    public final function usesSession(): bool
    {
        return $this->useSession;
    }

    /**
     * Uses main only.
     * @return bool
     */
    public final function usesMainOnly(): bool
    {
        return $this->useMainOnly;
    }

    /**
     * Is rest.
     * @return bool
     */
    public final function isRest(): bool
    {
        return $this instanceof RestService;
    }

    /**
     * Is site.
     * @return bool
     */
    public final function isSite(): bool
    {
        return $this instanceof SiteService;
    }

    /**
     * Is main service.
     * @return bool
     */
    public final function isMainService(): bool
    {
        return $this->name == ServiceInterface::SERVICE_MAIN . ServiceInterface::SERVICE_NAME_SUFFIX;
    }

    /**
     * Is fail service.
     * @return bool
     */
    public final function isFailService(): bool
    {
        return $this->name == ServiceInterface::SERVICE_FAIL . ServiceInterface::SERVICE_NAME_SUFFIX;
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
     * Serve.
     * @return any|null
     */
    public final function serve()
    {
        $request = $this->app->request();
        $response = $this->app->response();

        [$serviceMain, $methodMain, $methodOnBefore, $methodOnAfter] = [
            ServiceInterface::SERVICE_MAIN, ServiceInterface::METHOD_MAIN,
            ServiceInterface::METHOD_ON_BEFORE, ServiceInterface::METHOD_ON_AFTER];

        // Redirect "/main" to "/".
        $serviceName = $request->uri()->segment(1);
        if ($serviceName != null && strtolower($serviceName) == strtolower($serviceMain)) {
            $response->redirect('/', 301)->end();
            return null;
        }
        // Redirect "/<service>/main" to "/<service>/".
        $serviceMethod = $request->uri()->segment(2);
        if ($serviceMethod != null && strtolower($serviceMethod) == $methodMain) {
            $response->redirect('/'. strtolower($serviceName), 301)->end();
            return null;
        }

        // Call service onBefore method.
        if (method_exists($this, $methodOnBefore)) {
            $this->$methodOnBefore();
        }

        $method = $this->getMethod();
        $methodArguments = (array) $this->getMethodArguments($method);

        // Call main if method is main already for both Rest/Site.
        if ($method == '' || $method == $methodMain) {
            $return = $this->$methodMain($methodArguments);
        } elseif ($this->isRest()) {
            // These are available method for Rest.
            static $methods = ['get', 'post', 'put', 'delete'];

            if (in_array($method, $methods)) {
                $return = $this->$method(...$methodArguments);
            }
        } elseif ($this->isSite()) {
            if (method_exists($this, $method)) {
                $return = $this->$method(...$methodArguments);
            }
        } else {
            // Set response status to 404 indicating method not found.
            $response->setStatus(404);

            // Call FailService.main() actually that already was set in ServiceFactory.create().
            $return = $this->$methodMain($methodArguments);
        }

        // Call service onAfter method.
        if (method_exists($this, $methodOnAfter)) {
            $this->$methodOnAfter();
        }

        return $return;
    }

    /**
     * Load view.
     * @param  array<string, string>|null $partials
     * @return void
     * @throws froq\service\ServiceException
     */
    public final function loadView(array $partials = null): void
    {
        if (isset($this->view)) return;

        $class = 'froq\view\View';
        if (!class_exists($class)) {
            throw new ServiceException(sprintf('Class %s not found, be sure loaded it adding '.
                ' froq/froq-view into composer.json file', $class));
        }

        // This can be defined in child class.
        if (isset($this->viewPartials)) {
            $partials = $this->viewPartials;
        }

        $this->view = new $class($this, $partials);
    }

    /**
     * Load model.
     * @return void
     * @throws froq\service\ServiceException
     */
    public final function loadModel(): void
    {
        if (isset($this->model)) return;

        $file = sprintf('%s/app/service/%s/model/model.php', APP_DIR, $this->getName());
        if (!file_exists($file)) {
            throw new ServiceException(sprintf('Cannot load %s model, model file %s not found',
                $this->getName(), $file));
        }

        // Eg: FooService => FooModel.
        $class = sprintf('froq\app\database\%sModel', $this->getShortName());
        if (!class_exists($class)) {
            throw new ServiceException(sprintf('Cannot load %s model, model class %s not found',
                $this->getName(), $class));
        }

        $this->model = new $class($this);
    }
}
