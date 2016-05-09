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

        // aliases
        $serviceAliases = $this->app->config->get('app.service.aliases', []);

        // detect service name
        $serviceNameAlias = '';
        $serviceName = strtolower($this->app->request->uri->segment(0, ''));
        // check alias
        if (array_key_exists($serviceName, $serviceAliases)
            && isset($serviceAliases[$serviceName][0])) {
            $serviceNameAlias = $serviceName;
            $serviceName = $serviceAliases[$serviceName][0];
        } else {
            $serviceName = $serviceName ?: ServiceInterface::SERVICE_MAIN;
        }

        $this->serviceName = $this->toServiceName($serviceName);
        $this->serviceFile = $this->toServiceFile($this->serviceName);
        $this->serviceClass = $this->toServiceClass($this->serviceName);

        // set service as FailService
        if (!$this->isServiceExists()) {
            set_global('app.service.view.fail', [
                'code' => 404,
                'text' => sprintf('Service not found [%s]', $this->serviceName),
            ]);

            $this->serviceName   = ServiceInterface::SERVICE_FAIL . ServiceInterface::SERVICE_NAME_SUFFIX;
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
                $serviceMethod = strtolower($this->app->request->uri->segment(1, ''));
                // check alias
                if (isset($serviceAliases[$serviceNameAlias]['methods'])
                    && array_key_exists($serviceMethod, $serviceAliases[$serviceNameAlias]['methods'])) {
                    $this->serviceMethod = $this->toServiceMethod(
                        $serviceAliases[$serviceNameAlias]['methods'][$serviceMethod]);
                } elseif ($serviceMethod == '' || $serviceMethod == ServiceInterface::METHOD_MAIN) {
                    $this->serviceMethod = $serviceMethod;
                } else {
                    $this->serviceMethod = $this->toServiceMethod($serviceMethod);
                }
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
                $this->serviceName   = ServiceInterface::SERVICE_FAIL . ServiceInterface::SERVICE_NAME_SUFFIX;
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
                $ref = new \ReflectionMethod($this->serviceClass, $this->serviceMethod);
                foreach ($ref->getParameters() as $i => $param) {
                    if (!isset($methodArgs[$i])) {
                        $methodArgs[$i] = $param->isDefaultValueAvailable()
                            ? $param->getDefaultValue() : null;
                    }
                }

                $this->service->setMethodArgs($methodArgs);
            }
        }
    }

    /**
     * Check service exists.
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
     * Check service method exists.
     * @return bool
     */
    final public function isServiceMethodExists(): bool
    {
        return ($this->service && method_exists($this->service, $this->serviceMethod));
    }

    /**
     * Create service.
     * @return Froq\Service\ServiceInterface
     */
    final public function createService(): ServiceInterface
    {
        return new $this->serviceClass($this->app, $this->serviceName, $this->serviceMethod);
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

    /**
     * Prepare service name.
     * @param  string $serviceName
     * @return string
     */
    final private function toServiceName(string $serviceName): string
    {
        $serviceName = preg_replace_callback('~-([a-z])~i', function($match) {
            return ucfirst($match[1]);
        }, ucfirst($serviceName));

        return sprintf('%s%s', $serviceName, ServiceInterface::SERVICE_NAME_SUFFIX);
    }

    /**
     * Prepare service method.
     * @param  string $serviceMethod
     * @return string
     */
    final private function toServiceMethod(string $serviceMethod): string
    {
        $serviceMethod = preg_replace_callback('~-([a-z])~i', function($match) {
            return ucfirst($match[1]);
        }, ucfirst($serviceMethod));

        return sprintf('%s%s', ServiceInterface::METHOD_NAME_PREFIX, $serviceMethod);
    }

    /**
     * Prepare service name.
     * @param  string $serviceName
     * @return string
     */
    final private function toServiceFile(string $serviceName): string
    {
        $serviceFile = sprintf('./app/service/%s/%s.php', $serviceName, $serviceName);
        if (!is_file($serviceFile) && (
            $serviceName == ServiceInterface::SERVICE_MAIN . ServiceInterface::SERVICE_NAME_SUFFIX ||
            $serviceName == ServiceInterface::SERVICE_FAIL . ServiceInterface::SERVICE_NAME_SUFFIX
        )) {
            $serviceFile = sprintf('./app/service/default/%s/%s.php', $serviceName, $serviceName);
        }

        return $serviceFile;
    }

    /**
     * Prepare service class.
     * @param  string $serviceName
     * @return string
     */
    final public function toServiceClass(string $serviceName): string
    {
        return sprintf('%s%s', ServiceInterface::NAMESPACE, $serviceName);
    }
}
