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
 * @object     Froq\Service\ServiceFactory
 * @author     Kerem Güneş <k-gun@mail.com>
 */
abstract /* static final (fuck fuck fuuuck!!) */ class ServiceFactory
{
    /**
     * Create.
     * @param  Froq\App $app
     * @return ?Froq\Service\Service
     * @throws Froq\Service\ServiceException
     */
    public static final function create(App $app): ?Service
    {
        $request = $app->request();
        $requestUri = $request->uri();
        $requestMethod = $request->method();

        // detect service name if provided
        $service = null;
        $serviceName = strtolower($requestUri->segment(0, ''));
        $serviceNameAlias = '';
        $serviceMethod = null;
        $serviceMethodArguments = null;
        $serviceAliases = $app->configValue('app.service.aliases', []);

        // main
        if (empty($serviceName)) {
            $serviceName = Service::SERVICE_MAIN;
        }
        // aliases
        elseif (!empty($serviceAliases[$serviceName][0])) {
            $serviceNameAlias = $serviceName;
            $serviceName = $serviceAliases[$serviceName][0]; // 0 => name, methods => ...
        }
        // regexp routes
        else if (!empty($serviceAliases['@@@'])) {
            $uriPath = $requestUri->getPath();
            foreach ($serviceAliases['@@@'] as $route) {
                // these are required
                if (empty($route['pattern']) || empty($route['method'])) {
                    throw new ServiceException('Both pattern and method are required for RegExp aliases!');
                }
                if (preg_match($route['pattern'], $uriPath, $match)) {
                    $serviceName = $route[0];
                    $serviceMethod = $route['method'];
                    $serviceMethodArguments = array_slice($match, 1);
                    break;
                }
            }
        }

        $serviceName = self::toServiceName($serviceName);
        $serviceFile = self::toServiceFile($serviceName);
        $serviceClass = self::toServiceClass($serviceName);

        // set service as FailService
        if (!self::isServiceExists($serviceFile, $serviceClass)) {
            set_global('app.service.fail', [
                'code' => 404,
                'text' => sprintf('Service not found [%s]', $serviceName),
            ]);

            $serviceName   = Service::SERVICE_FAIL . Service::SERVICE_NAME_SUFFIX;
            $serviceMethod = Service::METHOD_MAIN;
            $serviceFile   = self::toServiceFile($serviceName);
            $serviceClass  = self::toServiceClass($serviceName);
        }

        $service = new $serviceClass($app, $serviceName, $serviceMethod);

        if (!$service->isFailService()) {
            // detect service method
            if ($service->usesMainOnly()) {
                $serviceMethod = Service::METHOD_MAIN;
            } elseif ($service->isSite()) {
                if (empty($serviceMethod)) {
                    // from segment
                    $serviceMethod = strtolower($requestUri->segment(1, ''));
                }

                if (isset($serviceAliases[$serviceNameAlias]['methods'][$serviceMethod])) { // alias
                    $serviceMethod = self::toServiceMethod($serviceAliases[$serviceNameAlias]['methods'][$serviceMethod]);
                } elseif ($serviceMethod == '' || $serviceMethod == Service::METHOD_MAIN) {
                    $serviceMethod = Service::METHOD_MAIN;
                } else {
                    $serviceMethod = self::toServiceMethod($serviceMethod);
                }
            } elseif ($service->isRest()) {
                // from request method
                $serviceMethod = strtolower($requestMethod->getName());
            }

            // check method
            if (!self::isServiceMethodExists($service, $serviceMethod)) {
                // check fallback method
                if (self::isServiceFallMethodExists($service)) {
                    $serviceMethod = Service::METHOD_FALL;
                } else {
                    set_global('app.service.fail', [
                        'code' => 404,
                        'text' => sprintf('Service method not found [%s::%s()]', $serviceName, $serviceMethod)
                    ]);

                    // overwrite
                    $serviceName   = Service::SERVICE_FAIL . Service::SERVICE_NAME_SUFFIX;
                    $serviceMethod = Service::METHOD_MAIN;
                    $serviceFile   = self::toServiceFile($serviceName);
                    $serviceClass  = self::toServiceClass($serviceName);

                    // re-create service as FailService
                    $service = new $serviceClass($app, $serviceName, $serviceMethod);
                }
            }

            $service->setMethod($serviceMethod);

            // set service method args
            if (self::isServiceMethodExists($service, $serviceMethod)) {
                $serviceMethodArguments = isset($serviceMethodArguments)
                    ? $serviceMethodArguments : $requestUri->segmentArguments($service->isSite() ? 2 : 1);

                $ref = new \ReflectionMethod($serviceClass, $serviceMethod);
                foreach ($ref->getParameters() as $i => $param) {
                    if (!isset($serviceMethodArguments[$i])) {
                        $serviceMethodArguments[$i] = $param->isDefaultValueAvailable()
                            ? $param->getDefaultValue() : null;
                    }
                }

                $service->setMethodArguments($serviceMethodArguments);
            }
        }

        return $service;
    }

    /**
     * Is service exists.
     * @param  string $serviceFile
     * @param  string $serviceClass
     * @return bool
     */
    private static final function isServiceExists(string $serviceFile, string $serviceClass): bool
    {
        if (!is_file($serviceFile)) {
            return false;
        }

        return class_exists($serviceClass, true);
    }

    /**
     * Is service method exists.
     * @param  Froq\Service\Service $service
     * @param  string               $serviceMethod
     * @return bool
     */
    private static final function isServiceMethodExists(?Service $service, string $serviceMethod): bool
    {
        return $service && method_exists($service, $serviceMethod);
    }

    /**
     * Is service fall method exists.
     * @param  Froq\Service\Service $service
     * @return bool
     */
    private static final function isServiceFallMethodExists(?Service $service): bool
    {
        return $service && method_exists($service, Service::METHOD_FALL);
    }

    /**
     * To service name.
     * @param  string $serviceName
     * @return string
     */
    private static final function toServiceName(string $serviceName): string
    {
        $serviceName = ucfirst($serviceName);
        if (strpos($serviceName, '-')) {
            $serviceName = preg_replace_callback('~-([a-z])~i', function ($match) {
                return ucfirst($match[1]);
            }, $serviceName);
        }

        return sprintf('%s%s', $serviceName, Service::SERVICE_NAME_SUFFIX);
    }

    /**
     * To service method.
     * @param  string $serviceMethod
     * @return string
     */
    private static final function toServiceMethod(string $serviceMethod): string
    {
        $serviceMethod = ucfirst($serviceMethod);
        if (strpos($serviceMethod, '-')) {
            $serviceMethod = preg_replace_callback('~-([a-z])~i', function ($match) {
                return ucfirst($match[1]);
            }, $serviceMethod);
        }

        return sprintf('%s%s', Service::METHOD_NAME_PREFIX, $serviceMethod);
    }

    /**
     * To service name.
     * @param  string $serviceName
     * @return string
     */
    private static final function toServiceFile(string $serviceName): string
    {
        $serviceFile = sprintf('%s/app/service/%s/%s.php', APP_DIR, $serviceName, $serviceName);
        if (!is_file($serviceFile) && (
            $serviceName == (Service::SERVICE_MAIN . Service::SERVICE_NAME_SUFFIX)
                || $serviceName == (Service::SERVICE_FAIL . Service::SERVICE_NAME_SUFFIX)
        )) {
            $serviceFile = sprintf('%s/app/service/default/%s/%s.php', APP_DIR, $serviceName, $serviceName);
        }

        return $serviceFile;
    }

    /**
     * To service class.
     * @param  string $serviceName
     * @return string
     */
    private static final function toServiceClass(string $serviceName): string
    {
        return sprintf('%s%s', Service::NAMESPACE, $serviceName);
    }
}
