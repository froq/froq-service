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

/**
 * @package    Froq
 * @subpackage Froq\Service
 * @object     Froq\Service\ServiceAdapter
 * @author     Kerem Güneş <k-gun@mail.com>
 */
final class ServiceAdapter
{
    /**
     * App object.
     * @var Froq\App
     */
    private $app;

    /**
     * Service object.
     * @var Froq\Service\Service
     */
    private $service;

    /**
     * Service name.
     * @var string
     */
    private $serviceName;

    /**
     * Service method.
     * @var string
     */
    private $serviceMethod;

    /**
     * Service file.
     * @var string
     */
    private $serviceFile;

    /**
     * Service class.
     * @var string
     */
    private $serviceClass;

    /**
     * Constructor.
     * @param Froq\App $app
     */
    final public function __construct(App $app)
    {
        $this->app = $app;

        $request = $this->app->request();
        $requestUri = $request->uri();
        $requestMethod = $request->method();

        // detect service name if provided
        $serviceNameAlias = '';
        $serviceName = strtolower($requestUri->segment(0, ''));
        $serviceMethod = null;

        $serviceAliases = $app->getConfigValue('app.service.aliases', []);
        if (!empty($serviceAliases[$serviceName][0])) { // 0 => name
            $serviceNameAlias = $serviceName;
            $serviceName = $serviceAliases[$serviceName][0];
        } else if (!empty($serviceAliases['@@@'])) { // regexp routes
            $uriPath = $requestUri->getPath();
            foreach ($serviceAliases['@@@'] as $route) {
                // these are required
                if (empty($route['pattern']) || empty($route['method'])) {
                    throw new ServiceException('Both pattern and method are required for RegExp aliases!');
                }
                if (preg_match($route['pattern'], $uriPath, $match)) {
                    $serviceName = $route[0];
                    $serviceMethod = $route['method'];
                    $methodArguments = array_slice($match, 1);
                    break;
                }
            }
        } else {
            $serviceName = $serviceName ?: Service::SERVICE_MAIN;
        }

        $this->serviceName = $this->toServiceName($serviceName);
        $this->serviceFile = $this->toServiceFile($this->serviceName);
        $this->serviceClass = $this->toServiceClass($this->serviceName);

        // set service as FailService
        if (!$this->isServiceExists()) {
            set_global('app.service.fail', [
                'code' => 404,
                'text' => sprintf('Service not found [%s]', $this->serviceName),
            ]);

            $this->serviceName   = Service::SERVICE_FAIL . Service::SERVICE_NAME_SUFFIX;
            $this->serviceMethod = Service::METHOD_MAIN;
            $this->serviceFile   = $this->toServiceFile($this->serviceName);
            $this->serviceClass  = $this->toServiceClass($this->serviceName);
        }

        $this->service = $this->createService();

        if (!$this->service->isFailService()) {
            // detect service method
            if ($this->service->useMainOnly) {
                $this->serviceMethod = Service::METHOD_MAIN;
            } elseif ($this->service->protocol == Service::PROTOCOL_SITE) {
                if (empty($serviceMethod)) {
                    // from segment
                    $serviceMethod = strtolower($requestUri->segment(1, ''));
                }

                if (isset($serviceAliases[$serviceNameAlias]['methods'][$serviceMethod])) { // alias
                    $this->serviceMethod = $this->toServiceMethod($serviceAliases[$serviceNameAlias]['methods'][$serviceMethod]);
                } elseif ($serviceMethod == '' || $serviceMethod == Service::METHOD_MAIN) {
                    $this->serviceMethod = Service::METHOD_MAIN;
                } else {
                    $this->serviceMethod = $this->toServiceMethod($serviceMethod);
                }
            } elseif ($this->service->protocol == Service::PROTOCOL_REST) {
                // from request method
                $this->serviceMethod = strtolower($requestMethod->getName());
            }

            // check method
            if (!$this->isServiceMethodExists()) {
                // check fallback method
                if ($this->isServiceMethodFallExists()) {
                    $this->serviceMethod = Service::METHOD_FALL;
                } else {
                    set_global('app.service.fail', [
                        'code' => 404,
                        'text' => sprintf('Service method not found [%s::%s()]',
                            $this->serviceName, $this->serviceMethod)
                    ]);

                    // overwrite
                    $this->serviceName   = Service::SERVICE_FAIL . Service::SERVICE_NAME_SUFFIX;
                    $this->serviceMethod = Service::METHOD_MAIN;
                    $this->serviceFile   = $this->toServiceFile($this->serviceName);
                    $this->serviceClass  = $this->toServiceClass($this->serviceName);

                    // re-create service as FailService
                    $this->service = $this->createService();
                }
            }

            $this->service->setMethod($this->serviceMethod);

            // set service method args
            if ($this->isServiceMethodExists()) {
                $methodArguments = isset($methodArguments) ? $methodArguments : $requestUri->segmentArguments(
                    $this->service->protocol == Service::PROTOCOL_SITE ? 2 : 1
                );
                $ref = new \ReflectionMethod($this->serviceClass, $this->serviceMethod);
                foreach ($ref->getParameters() as $i => $param) {
                    if (!isset($methodArguments[$i])) {
                        $methodArguments[$i] = $param->isDefaultValueAvailable()
                            ? $param->getDefaultValue() : null;
                    }
                }

                $this->service->setMethodArguments($methodArguments);
            }
        }
    }

    /**
     * Is service exists.
     * @return bool
     */
    final public function isServiceExists(): bool
    {
        if (!is_file($this->serviceFile)) {
            return false;
        }

        return class_exists($this->serviceClass, true);
    }

    /**
     * Is service method exists.
     * @return bool
     */
    final public function isServiceMethodExists(): bool
    {
        return ($this->service && method_exists($this->service, $this->serviceMethod));
    }

    /**
     * Is service fallback method exists.
     * @return bool
     */
    final public function isServiceMethodFallExists(): bool
    {
        return ($this->service && method_exists($this->service, Service::METHOD_FALL));
    }

    /**
     * Create service.
     * @return Froq\Service\Service
     */
    final private function createService(): Service
    {
        return new $this->serviceClass($this->app, $this->serviceName, $this->serviceMethod);
    }

    /**
     * Get service.
     *
     * @return Froq\Service\Service
     */
    final public function getService(): Service
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

    /**
     * To service name.
     * @param  string $serviceName
     * @return string
     */
    final private function toServiceName(string $serviceName): string
    {
        $serviceName = preg_replace_callback('~-([a-z])~i', function($match) {
            return ucfirst($match[1]);
        }, ucfirst($serviceName));

        return sprintf('%s%s', $serviceName, Service::SERVICE_NAME_SUFFIX);
    }

    /**
     * To service method.
     * @param  string $serviceMethod
     * @return string
     */
    final private function toServiceMethod(string $serviceMethod): string
    {
        $serviceMethod = preg_replace_callback('~-([a-z])~i', function($match) {
            return ucfirst($match[1]);
        }, ucfirst($serviceMethod));

        return sprintf('%s%s', Service::METHOD_NAME_PREFIX, $serviceMethod);
    }

    /**
     * To service name.
     * @param  string $serviceName
     * @return string
     */
    final private function toServiceFile(string $serviceName): string
    {
        $serviceFile = sprintf('./app/service/%s/%s.php', $serviceName, $serviceName);
        if (!is_file($serviceFile) && (
            $serviceName == Service::SERVICE_MAIN . Service::SERVICE_NAME_SUFFIX ||
            $serviceName == Service::SERVICE_FAIL . Service::SERVICE_NAME_SUFFIX
        )) {
            $serviceFile = sprintf('./app/service/default/%s/%s.php', $serviceName, $serviceName);
        }

        return $serviceFile;
    }

    /**
     * To service class.
     * @param  string $serviceName
     * @return string
     */
    final public function toServiceClass(string $serviceName): string
    {
        return sprintf('%s%s', Service::NAMESPACE, $serviceName);
    }
}
