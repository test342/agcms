<?php namespace AGCMS\Middleware;

use AGCMS\Controller\Base;
use AGCMS\Interfaces\Middleware;
use AGCMS\Request;
use Closure;
use Symfony\Component\HttpFoundation\Response;

class Utf8Url implements Middleware
{
    /**
     * Generate a redirect if URL was not UTF-8 encoded.
     *
     * @param Request $request
     * @param Closure $next
     *
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestUrl = urldecode($request->getRequestUri());

        $encoding = mb_detect_encoding($requestUrl, 'UTF-8, ISO-8859-1');
        if ('UTF-8' === $encoding) {
            return $next($request);
        }

        // Windows-1252 is a superset of iso-8859-1
        if (!$encoding || 'ISO-8859-1' === $encoding) {
            $encoding = 'windows-1252';
        }

        $requestUrl = mb_convert_encoding($requestUrl, 'UTF-8', $encoding);

        return (new Base())->redirect($request, $requestUrl, Response::HTTP_MOVED_PERMANENTLY);
    }
}