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

use froq\App;

/**
 * Service factory.
 * @package froq\service
 * @object  froq\service\ServiceFactory
 * @author  Kerem Güneş <k-gun@mail.com>
 * @since   1.0
 */
final /* static final fuck fuck fuuuuuuuuuuck!!! */ class ServiceFactory
{
    /**
     * Create.
     * @param  froq\App $app
     * @return ?froq\service\Service
     * @throws froq\service\ServiceException
     */
    public static final function create(App $app): ?Service
    {
        $request = $app->request();
        $response = $app->response();
        $requestUri = $request->uri();
        $requestMethod = $request->method();

        // detect service name if provided
        $service = null;
        $serviceName = $serviceNameOrig = strtolower($requestUri->segment(1, ''));
        $serviceNameAlias = '';
        $serviceMethod = null;
        $serviceMethodFilter = null;
        $serviceMethodArguments = null;
        $serviceAliases = $app->config('service.aliases');

        // main
        if ($serviceName == '') {
            $serviceName = Service::SERVICE_MAIN;
        } else {
            $serviceName = self::toServiceName($serviceName);
            $serviceFile = self::toServiceFile($serviceName);
            $serviceClass = self::toServiceClass($serviceName);

            if (!self::isServiceExists($serviceFile, $serviceClass)) {
                // check aliases
                if (isset($serviceAliases[$serviceNameOrig][0])) {
                    $serviceNameAlias = $serviceNameOrig;
                    // 0 => name, methods => ...
                    $serviceName = $serviceAliases[$serviceNameAlias][0];
                    // 0 => name, method => ... if given for one invoke direction
                    $serviceMethod = $serviceAliases[$serviceNameAlias]['method'] ?? null;
                    // 0 => name, method => ..., methodFilter => ... if given for one invoke direction filter
                    $serviceMethodFilter = $serviceAliases[$serviceNameAlias]['methodFilter'] ?? null;
                }
                // check regexp routes
                else if (isset($serviceAliases['~~'])) {
                    $uriPath = $requestUri->get('path');
                    foreach ((array) $serviceAliases['~~'] as $route) {
                        // these are required
                        if (empty($route['method']) || empty($route['pattern'])) {
                            throw new ServiceException("Both 'method' and 'pattern' are required for RegExp aliases");
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

            // if real names disabled
            $allowRealName = $app->config('service.allowRealName');
            if (!$allowRealName && $serviceNameAlias != '' && self::isServiceExists($serviceFile, $serviceClass)) {
                $serviceName = '';
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

            // set response status
            $response->setStatus(404);

            $serviceName   = Service::SERVICE_FAIL . Service::SERVICE_NAME_SUFFIX;
            $serviceMethod = Service::METHOD_MAIN;
            $serviceFile   = self::toServiceFile($serviceName);
            $serviceClass  = self::toServiceClass($serviceName);
        }

        $service = new $serviceClass($app, $serviceName, $serviceMethod);

        // detect and set service method
        if (!$service->isFailService()) {
            if ($serviceMethod != '') { // pass
                // @note: method could be checked in main() [if $useMainOnly=true in service]
                // @note: this will override $useMainOnly option in service
            } elseif ($service->usesMainOnly()) {
                // main only
                $serviceMethod = Service::METHOD_MAIN;
            } elseif ($service->isSite()) {
                // from segment
                if ($serviceMethod == '') {
                    $serviceMethod = strtolower($requestUri->segment(2, ''));
                }

                // alias
                if (isset($serviceAliases[$serviceNameAlias]['methods'][$serviceMethod])) {
                    $serviceMethod = self::toServiceMethod($serviceAliases[$serviceNameAlias]['methods'][$serviceMethod]);
                } elseif ($serviceMethod == '' || $serviceMethod == Service::METHOD_MAIN) {
                    // main
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

                    // set response status
                    $response->setStatus(404);

                    // override
                    $serviceName   = Service::SERVICE_FAIL . Service::SERVICE_NAME_SUFFIX;
                    $serviceMethod = Service::METHOD_MAIN;
                    $serviceFile   = self::toServiceFile($serviceName);
                    $serviceClass  = self::toServiceClass($serviceName);

                    // re-create service as FailService
                    $service = new $serviceClass($app, $serviceName, $serviceMethod);
                }
            }

            $service->setMethod($serviceMethod);

            // set service method arguments
            if (self::isServiceMethodExists($service, $serviceMethod)) {
                $serviceMethodArguments = isset($serviceMethodArguments) ? $serviceMethodArguments
                    : $requestUri->segmentArguments($service->isSite() ? 2 : 1);

                $ref = new \ReflectionMethod($serviceClass, $serviceMethod);
                foreach ($ref->getParameters() as $i => $param) {
                    if (!isset($serviceMethodArguments[$i])) {
                        $serviceMethodArguments[$i] = $param->isDefaultValueAvailable()
                            ? $param->getDefaultValue() : null;
                    }
                }

                $service->setMethodArguments($serviceMethod, $serviceMethodArguments);

                // method filter
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
    public static final function toServiceName(string $serviceName): string
    {
        // foo-bar => FooBar
        $serviceName = ucfirst($serviceName);
        if (strpos($serviceName, '-')) {
            $serviceName = preg_replace_callback('~-([a-z])~i', function($match) {
                return ucfirst($match[1]);
            }, $serviceName);
        }

        // foo-bar => FooBarService
        if ($serviceName == Service::SERVICE_NAME_SUFFIX || substr($serviceName, -7) != Service::SERVICE_NAME_SUFFIX) {
            $serviceName .= Service::SERVICE_NAME_SUFFIX;
        }

        return $serviceName;
    }

    /**
     * To service name.
     * @param  string $serviceName
     * @return string
     */
    public static final function toServiceFile(string $serviceName): string
    {
        $serviceFile = sprintf('%s/app/service/%s/%s.php', APP_DIR, $serviceName, $serviceName);
        if (!file_exists($serviceFile) && (
            $serviceName == (Service::SERVICE_MAIN . Service::SERVICE_NAME_SUFFIX)
                || $serviceName == (Service::SERVICE_FAIL . Service::SERVICE_NAME_SUFFIX))) {
            $serviceFile = sprintf('%s/app/service/_default/%s/%s.php', APP_DIR, $serviceName, $serviceName);
        }

        return $serviceFile;
    }

    /**
     * To service class.
     * @param  string $serviceName
     * @return string
     */
    public static final function toServiceClass(string $serviceName): string
    {
        return sprintf('%s\\%s', Service::NAMESPACE, $serviceName);
    }

    /**
     * To service method.
     * @param  string $serviceMethod
     * @return string
     */
    public static final function toServiceMethod(string $serviceMethod): string
    {
        // foo-bar => FooBar
        $serviceMethod = ucfirst($serviceMethod);
        if (strpos($serviceMethod, '-')) {
            $serviceMethod = preg_replace_callback('~-([a-z])~i', function($match) {
                return ucfirst($match[1]);
            }, $serviceMethod);
        }

        // foo-bar => doFooBar
        return sprintf('%s%s', Service::METHOD_NAME_PREFIX, $serviceMethod);
    }

    /**
     * Is service exists.
     * @param  string $serviceFile
     * @param  string $serviceClass
     * @return bool
     */
    private static final function isServiceExists(string $serviceFile, string $serviceClass): bool
    {
        if (!file_exists($serviceFile)) {
            return false;
        }

        return class_exists($serviceClass, true);
    }

    /**
     * Is service method exists.
     * @param  froq\service\Service $service
     * @param  string               $serviceMethod
     * @return bool
     */
    private static final function isServiceMethodExists(?Service $service, string $serviceMethod): bool
    {
        return $service && method_exists($service, $serviceMethod);
    }

    /**
     * Is service fall method exists.
     * @param  froq\service\Service $service
     * @return bool
     */
    private static final function isServiceFallMethodExists(?Service $service): bool
    {
        return $service && method_exists($service, Service::METHOD_FALL);
    }
}
