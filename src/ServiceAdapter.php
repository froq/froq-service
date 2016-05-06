<?php
declare(strict_types=1);
namespace Froq\Service;
use Froq\App;

final class ServiceAdapter
{
    private $service;
    private $serviceName;
    private $serviceMethod;
    private $serviceMethodArgs = [];
    private $serviceFile;
    private $serviceClass;

    final public function __construct(App $app)
    {
        $this->app = $app;

        // detect service name
        $this->serviceName = ($serviceName = $this->app->request->uri->segment(0))
            ? $this->toServiceName($serviceName) : ServiceInterface::SERVICE_MAIN;
        // check alias
        $aliases = $this->app->config['app.service.aliases'];
        if (array_key_exists($this->serviceName, $aliases)) {
            $this->serviceName = $aliases[$this->serviceName];
        }

        $this->serviceFile = $this->toServiceFile($this->serviceName);
        $this->serviceClass = $this->toServiceClass($this->serviceName);

        // set service as FailService
        if (!$this->isServiceExists()) {
            set_global('app.service.view.fail', [
                'code' => 404,
                'text' => sprintf('Service not found [%s]', $this->serviceName),
            ]);

            $this->serviceName   = ServiceInterface::SERVICE_FAIL;
            $this->serviceMethod = ServiceInterface::METHOD_MAIN;
            $this->serviceFile   = $this->toServiceFile($this->serviceName);
            $this->serviceClass  = $this->toServiceClass($this->serviceName);
        }

        $this->service = $this->createService();

        if (!$this->service->isFailService()) {
            // detect service method
            if ($this->service->useMainOnly) {
                // main only
                $this->serviceMethod = ServiceInterface::METHOD_MAIN;
            } elseif ($this->service->protocol == ServiceInterface::PROTOCOL_SITE) {
                // from segment
                $this->serviceMethod = ($serviceMethod = $this->app->request->uri->segment(1))
                    ? $this->toServiceMethod($serviceMethod) : ServiceInterface::METHOD_MAIN;
            } elseif ($this->service->protocol == ServiceInterface::PROTOCOL_REST) {
                // from request method
                $this->serviceMethod = strtolower($this->app->request->method);
            }

            // check method
            if (!$this->isServiceMethodExists()) {
                set_global('app.service.view.fail', [
                    'code' => 404,
                    'text' => sprintf('Service method not found [%s::%s()]',
                        $this->serviceName, $this->serviceMethod)
                ]);

                // overwrite
                $this->serviceName   = ServiceInterface::SERVICE_FAIL;
                $this->serviceMethod = ServiceInterface::METHOD_MAIN;
                $this->serviceFile   = $this->toServiceFile($this->serviceName);
                $this->serviceClass  = $this->toServiceClass($this->serviceName);

                // re-create service as FailService
                $this->service = $this->createService();
            }

            // set service method
            $this->service->setMethod($this->serviceMethod);

            // set service method args
            if ($this->isServiceMethodExists()) {
                $methodArgs = array_slice($this->app->request->uri->segments(), 2);
                $methodArgsCount = count($methodArgs);
                $methodArgsCountRef = (new \ReflectionMethod($this->serviceClass, $this->serviceMethod))
                    ->getNumberOfParameters();
                if ($methodArgsCount < $methodArgsCountRef) {
                    $methodArgs += array_fill($methodArgsCount, $methodArgsCountRef - $methodArgsCount, null);
                }

                $this->service->setMethodArgs($this->methodArgs = $methodArgs);
            }
        }
    }

    final public function isServiceExists(): bool
    {
        if (!is_file($this->serviceFile)) {
            return false;
        }
        return class_exists($this->serviceClass, true);
    }
    final public function isServiceMethodExists(): bool
    {
        return ($this->service && method_exists($this->service, $this->serviceMethod));
    }

    final public function createService(): ServiceInterface
    {
        return new $this->serviceClass($this->app,
            $this->serviceName, $this->serviceMethod, $this->serviceMethodArgs);
    }

    /**
     * Get service.
     *
     * @return Froq\Service\ServiceInterface
     */
    final public function getService(): ServiceInterface
    {
        return $this->service;
    }

    /**
     * Get service name.
     *
     * @return string
     */
    final public function getServiceName(): string
    {
        return $this->serviceName;
    }

    /**
     * Get service method.
     *
     * @return string
     */
    final public function getServiceMethod(): string
    {
        return $this->serviceMethod;
    }

    /**
     * Get service file.
     *
     * @return string
     */
    final public function getServiceFile(): string
    {
        return $this->serviceFile;
    }

    final private function toServiceName(string $name): string
    {
        $name = preg_replace_callback('~-([a-z])~i', function($match) {
            return ucfirst($match[1]);
        }, ucfirst($name));
        return sprintf('%s%s', $name, ServiceInterface::SERVICE_NAME_SUFFIX);
    }

    final private function toServiceMethod(string $method): string
    {
        $method = preg_replace_callback('~-([a-z])~i', function($match) {
            return ucfirst($match[1]);
        }, ucfirst($method));
        return sprintf('%s%s', ServiceInterface::METHOD_NAME_PREFIX, $method);
    }

    final private function toServiceFile(string $serviceName, bool $load = false): string
    {
        $serviceFile = sprintf('./app/service/%s/%s.php', $serviceName, $serviceName);
        if (!is_file($serviceFile) && (
            $serviceName == ServiceInterface::SERVICE_MAIN ||
            $serviceName == ServiceInterface::SERVICE_FAIL
        )) {
            $serviceFile = sprintf('./app/service/default/%s/%s.php', $serviceName, $serviceName);
        }
        return $serviceFile;
    }

    final public function toServiceClass(string $serviceName): string
    {
        return sprintf('%s%s', ServiceInterface::NAMESPACE, $serviceName);
    }
}
