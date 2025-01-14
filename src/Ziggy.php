<?php

namespace Tightenco\Ziggy;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Reflector;
use Illuminate\Support\Str;
use JsonSerializable;
use ReflectionMethod;

class Ziggy implements JsonSerializable
{
    protected $port;
    protected $url;
    protected $group;
    protected $routes;

    public function __construct($group = null, string $url = null)
    {
        $this->group = $group;

        $this->url  = rtrim($url ?? url('/'), '/');
        $this->port = parse_url($this->url)['port'] ?? null;

        $this->routes = $this->nameKeyedRoutes();
    }

    private function applyFilters($group)
    {
        if ($group) {
            return $this->group($group);
        }

        // return unfiltered routes if user set both config options.
        if (config()->has('ziggy.except') && config()->has('ziggy.only')) {
            return $this->routes;
        }

        if (config()->has('ziggy.except')) {
            return $this->filter(config('ziggy.except'), false)->routes;
        }

        if (config()->has('ziggy.only')) {
            return $this->filter(config('ziggy.only'))->routes;
        }

        return $this->routes;
    }

    /**
     * Filter routes by group.
     */
    private function group($group)
    {
        if (is_array($group)) {
            $filters = [];

            foreach ($group as $groupName) {
                $filters = array_merge($filters, config("ziggy.groups.{$groupName}"));
            }

            return $this->filter($filters, true)->routes;
        }

        if (config()->has("ziggy.groups.{$group}")) {
            return $this->filter(config("ziggy.groups.{$group}"), true)->routes;
        }

        return $this->routes;
    }

    /**
     * Filter routes by name using the given patterns.
     */
    public function filter($filters = [], $include = true): self
    {
        $this->routes = $this->routes->filter(function ($route, $name) use ($filters, $include) {
            return Str::is(Arr::wrap($filters), $name) ? $include : !$include;
        });

        return $this;
    }

    /**
     * Get a list of the application's named routes, keyed by their names.
     */
    private function nameKeyedRoutes()
    {
        $routes = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if ($route->getName() &&
                !Str::startsWith($route->getName(), 'debugbar.') &&
                !Str::startsWith($route->getName(), 'generated::')) {
                $routes[$route->getName()] = $route;
            }
        }

        return collect($routes)->map(function ($route) {

                return collect(['uri' => $route->getUri(), 'methods' => $route->getMethods(), 'domain'=> $route->domain()])
                    ->only(['uri', 'methods'])->filter();
            });
    }

    /**
     * Convert this Ziggy instance to an array.
     */
    public function toArray(): array
    {
        return [
            'url'      => $this->url,
            'port'     => $this->port,
            'defaults' => method_exists(app('url'), 'getDefaultParameters')
                ? app('url')->getDefaultParameters()
                : [],
            'routes'   => $this->applyFilters($this->group)->toArray(),
        ];
    }

    /**
     * Convert this Ziggy instance into something JSON serializable.
     */
    public function jsonSerialize(): array
    {
        return array_merge($routes = $this->toArray(), [
            'defaults' => (object) $routes['defaults'],
        ]);
    }

    /**
     * Convert this Ziggy instance to JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Resolve route key names for any route parameters using Eloquent route model binding.
     */
    private function resolveBindings(array $routes): array
    {
        $scopedBindings = method_exists(head($routes) ?: '', 'bindingFields');

        foreach ($routes as $name => $route) {
            $bindings = [];

            foreach ($route->signatureParameters(UrlRoutable::class) as $parameter) {
                $model    = class_exists(Reflector::class)
                    ? Reflector::getParameterClassName($parameter)
                    : $parameter->getType()->getName();
                $override = $model === (new ReflectionMethod($model, 'getRouteKeyName'))->class;

                // Avoid booting this model if it doesn't override the default route key name
                $bindings[$parameter->getName()] = $override ? app($model)->getRouteKeyName() : 'id';
            }

            $routes[$name] = $scopedBindings ? array_merge($bindings, $route->bindingFields()) : $bindings;
        }

        return $routes;
    }
}
