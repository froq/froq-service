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
use froq\service\{ServiceInterface, ServiceException};
use ReflectionMethod;

/**
 * Service Factory.
 * @package froq\service
 * @object  froq\service\ServiceFactory
 * @author  Kerem Güneş <k-gun@mail.com>
 * @since   1.0
 * @static
 */
final class ServiceFactory
{
    /**
     * Create.
     * @param  froq\app\App $app
     * @return froq\service\ServiceInterface
     * @throws froq\service\ServiceException
     */
    public static function create(App $app): ServiceInterface
    {
        $request = $app->request();

        $service = null;
        $serviceName = strtolower($request->uri()->segment(1, ''));
        $serviceNameOrig = $serviceName;
        $serviceNameAlias = '';
        $serviceMethod = null;
        $serviceMethodFilter = null;
        $serviceMethodArguments = null;
        $serviceAliases = $app->config('service.aliases');

        // Main.
        if ($serviceName == '') {
            $serviceName = ServiceInterface::SERVICE_MAIN;
        } else {
            $serviceName = self::toServiceName($serviceName);
            $serviceFile = self::toServiceFile($serviceName);
            $serviceClass = self::toServiceClass($serviceName);

            if (!self::isServiceExists($serviceFile, $serviceClass)) {
                // Check aliases.
                if (isset($serviceAliases[$serviceNameOrig][0])) {
                    $serviceNameAlias = $serviceNameOrig;
                    // 0 => name, methods => ....
                    $serviceName = $serviceAliases[$serviceNameAlias][0];
                    // 0 => name, method => ... if given for one invoke direction.
                    $serviceMethod = $serviceAliases[$serviceNameAlias]['method'] ?? null;
                    // 0 => name, method => ..., methodFilter => ... if given for one invoke direction filter.
                    $serviceMethodFilter = $serviceAliases[$serviceNameAlias]['methodFilter'] ?? null;
                }
                // Check regexp routes.
                else if (isset($serviceAliases['~~'])) {
                    $uriPath = $request->uri()->get('path');
                    foreach ((array) $serviceAliases['~~'] as $route) {
                        if (empty($route['method']) || empty($route['pattern'])) {
                            throw new ServiceException('Both method and pattern are required for '.
                                'RegExp aliases');
                        }

                        if (preg_match($route['pattern'], $uriPath, $match)) {
                            $serviceName = $route[0];
                            $serviceMethod = $route['method'];
                            $serviceMethodFilter = $route['methodFilter'] ?? null;
                            $serviceMethodArguments = array_slice($match, 1);
                            break;
                        }
                    }
                }
            }

            // If real names disabled dump $serviceName, so that couse 404 error.
            $allowRealName = $app->config('service.allowRealName');
            if (!$allowRealName && $serviceNameAlias != ''
                && self::isServiceExists($serviceFile, $serviceClass)) {
                $serviceName = '';
            }
        }

        $serviceName = self::toServiceName($serviceName);
        $serviceFile = self::toServiceFile($serviceName);
        $serviceClass = self::toServiceClass($serviceName);

        // Set service as FailService if no exists.
        if (!self::isServiceExists($serviceFile, $serviceClass)) {
            // Save info stack.
            app_fail('service', ['code' => 404, 'text' => sprintf('Service not found [%s]',
                $serviceName)]);

            $serviceName   = ServiceInterface::SERVICE_FAIL . ServiceInterface::SERVICE_NAME_SUFFIX;
            $serviceMethod = ServiceInterface::METHOD_MAIN;
            $serviceFile   = self::toServiceFile($serviceName);
            $serviceClass  = self::toServiceClass($serviceName);
        }

        $service = new $serviceClass($app, $serviceName, $serviceMethod);

        // Detect and set service method if service exists.
        if (!$service->isFailService()) {
            if ($serviceMethod != '') {
                // Pass, so method could be checked in main() [if $useMainOnly=true in service]
                // this will override $useMainOnly option property in service.
            } elseif ($service->usesMainOnly()) {
                // Main only triggered if $useMainOnly=true in service.
                $serviceMethod = ServiceInterface::METHOD_MAIN;
            } elseif ($service->isRest()) {
                // From request method.
                $serviceMethod = strtolower($request->method()->getName());
            } elseif ($service->isSite()) {
                // From segment.
                if ($serviceMethod == '') {
                    $serviceMethod = strtolower($request->uri()->segment(2, ''));
                }

                if (isset($serviceAliases[$serviceNameAlias]['methods'][$serviceMethod])) {
                    // Aliases can detect and change service method.
                    $serviceMethod = self::toServiceMethod(
                        $serviceAliases[$serviceNameAlias]['methods'][$serviceMethod]);
                } elseif ($serviceMethod == '' || $serviceMethod == ServiceInterface::METHOD_MAIN) {
                    $serviceMethod = ServiceInterface::METHOD_MAIN;
                } else {
                    $serviceMethod = self::toServiceMethod($serviceMethod);
                }
            }

            // Check method and set it as <service>.fail() or FailService.main().
            if (!self::isServiceMethodExists($service, $serviceMethod)) {
                if (self::isServiceMethodExists($service, ServiceInterface::METHOD_FAIL)) {
                    $serviceMethod = ServiceInterface::METHOD_FAIL;
                } else {
                    // Save info stack.
                    app_fail('service', ['code' => 404, 'text' => sprintf('Service method not '.
                        'found [%s::%s()]', $serviceName, $serviceMethod)]);

                    // @override
                    $serviceName   = ServiceInterface::SERVICE_FAIL . ServiceInterface::SERVICE_NAME_SUFFIX;
                    $serviceMethod = ServiceInterface::METHOD_MAIN;
                    $serviceFile   = self::toServiceFile($serviceName);
                    $serviceClass  = self::toServiceClass($serviceName);

                    // Re-create service as FailService.
                    $service = new $serviceClass($app, $serviceName, $serviceMethod);
                }
            }

            $service->setMethod($serviceMethod);

            // Set service method arguments.
            if (self::isServiceMethodExists($service, $serviceMethod)) {
                $serviceMethodArguments = $serviceMethodArguments
                    ?? $request->uri()->segmentArguments($service->isRest() ? 1 : 2);

                $ref = new ReflectionMethod($serviceClass, $serviceMethod);
                foreach ($ref->getParameters() as $i => $param) {
                    if (!isset($serviceMethodArguments[$i])) {
                        $serviceMethodArguments[$i] = $param->isDefaultValueAvailable()
                            ? $param->getDefaultValue() : null;
                    }
                }

                $service->setMethodArguments($serviceMethod, $serviceMethodArguments);

                // Apply method filter if provided in service aliases.
                if ($serviceMethodFilter != null) {
                    $serviceMethodFilter->call($service);
                }
            }
        }

        return $service;
    }

    /**
     * To service name.
     * @param  string $serviceName
     * @return string
     */
    public static function toServiceName(string $serviceName): string
    {
        $serviceName = $this->prepareName($serviceName);

        // foo-bar => FooBarService
        if ($serviceName == ServiceInterface::SERVICE_NAME_SUFFIX
            || substr($serviceName, -7) != ServiceInterface::SERVICE_NAME_SUFFIX) {
            $serviceName .= ServiceInterface::SERVICE_NAME_SUFFIX;
        }

        return $serviceName;
    }

    /**
     * To service file.
     * @param  string $serviceName
     * @return string
     */
    public static function toServiceFile(string $serviceName): string
    {
        $serviceFile = sprintf('%s/app/service/%s/%s.php', APP_DIR, $serviceName, $serviceName);
        if (!file_exists($serviceFile) && (
               $serviceName == (ServiceInterface::SERVICE_MAIN . ServiceInterface::SERVICE_NAME_SUFFIX)
            || $serviceName == (ServiceInterface::SERVICE_FAIL . ServiceInterface::SERVICE_NAME_SUFFIX)
        )) {
            $serviceFile = sprintf('%s/app/service/_default/%s/%s.php', APP_DIR, $serviceName,
                $serviceName);
        }

        return $serviceFile;
    }

    /**
     * To service class.
     * @param  string $serviceName
     * @return string
     */
    public static function toServiceClass(string $serviceName): string
    {
        return sprintf('%s\%s', ServiceInterface::NAMESPACE, $serviceName);
    }

    /**
     * To service method.
     * @param  string $serviceMethod
     * @return string
     */
    public static function toServiceMethod(string $serviceMethod): string
    {
        $serviceMethod = $this->prepareName($serviceMethod);

        // foo-bar => doFooBar
        return sprintf('%s%s', ServiceInterface::METHOD_NAME_PREFIX, $serviceMethod);
    }

    /**
     * Is service exists.
     * @param  string $serviceFile
     * @param  string $serviceClass
     * @return bool
     * @internal
     */
    private static function isServiceExists(string $serviceFile, string $serviceClass): bool
    {
        return file_exists($serviceFile) && class_exists($serviceClass, true);
    }

    /**
     * Is service method exists.
     * @param  froq\service\ServiceInterface $service
     * @param  string                        $serviceMethod
     * @return bool
     * @internal
     */
    private static function isServiceMethodExists(ServiceInterface $service, string $serviceMethod): bool
    {
        return method_exists($service, $serviceMethod);
    }

    /**
     * Prepare name.
     * @param  string $name
     * @return string
     * @since  4.0
     * @internal
     */
    private function prepareName(string $name): string
    {
        $name = ucfirst($name);

        // foo-bar => FooBar
        if (strpos($name, '-')) {
            $name = preg_replace_callback('~-([a-z])~i', function($match) {
                return ucfirst($match[1]);
            }, $name);
        }

        return $name;
    }
}
