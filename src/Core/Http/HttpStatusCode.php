<?php

declare(strict_types=1);

namespace Zara\Core\Http;

/**
 * HTTP Status Codes
 * This class defines constants for HTTP status codes as per RFC 7231 and other relevant RFCs.
 * It provides a structured way to reference HTTP status codes throughout the application.  
 * @package App\Core\Http
 */
final class HttpStatusCode
{  
    // 1xx Informational
    public const CONTINUE = 100; // RFC 7231, "Continue"
    public const SWITCHING_PROTOCOLS = 101; // RFC 7231, "Switching Protocols"
    public const PROCESSING = 102; // RFC 2518, WebDAV, "Processing"
    public const EARLY_HINTS = 103; // RFC 8297, "Early Hints"

    // 2xx Success
    public const OK = 200;
    public const CREATED = 201;
    public const ACCEPTED = 202;
    public const NON_AUTHORITATIVE_INFORMATION = 203; // RFC 7231, "Non-Authoritative Information"
    public const NO_CONTENT = 204;
    public const RESET_CONTENT = 205; // RFC 7231, "Reset Content"
    public const PARTIAL_CONTENT = 206; // RFC 7233, "Partial Content"
    public const MULTI_STATUS = 207; // RFC 4918, WebDAV, "Multi-Status"
    public const ALREADY_REPORTED = 208; // RFC 5842, WebDAV, "Already Reported"
    public const IM_USED = 226; // RFC 3229, "IM Used"

    // 3xx Redirection
    public const MULTIPLE_CHOICES = 300; // RFC 7231, "Multiple Choices"
    public const MOVED_PERMANENTLY = 301; // RFC 7231, "Moved Permanently"
    public const FOUND = 302; // RFC 7231, "Found"
    public const SEE_OTHER = 303; // RFC 7231, "See Other"
    public const NOT_MODIFIED = 304; // RFC 7232, "Not Modified"
    public const USE_PROXY = 305; // RFC 7231, "Use Proxy"
    public const TEMPORARY_REDIRECT = 307; // RFC 7231, "Temporary Redirect"
    public const PERMANENT_REDIRECT = 308; // RFC 7538, "Permanent Redirect"

    // 4xx Client Error
    public const BAD_REQUEST = 400;
    public const UNAUTHORIZED = 401;
    public const FORBIDDEN = 403;
    public const NOT_FOUND = 404;
    public const METHOD_NOT_ALLOWED = 405;
    public const NOT_ACCEPTABLE = 406; // RFC 7231, "Not Acceptable"
    public const REQUEST_TIMEOUT = 408; // RFC 7231, "Request Timeout"
    public const CONFLICT = 409;
    public const LENGTH_REQUIRED = 411; // RFC 7231, "Length Required"
    public const PRECONDITION_FAILED = 412;
    public const REQUEST_ENTITY_TOO_LARGE = 413; // RFC 7231, "Request Entity Too Large"
    public const REQUEST_URI_TOO_LONG = 414; // RFC 7231, "Request URI Too Long"
    public const UNSUPPORTED_MEDIA_TYPE = 415; // RFC 7231, 
    public const RANGE_NOT_SATISFIABLE = 416; // RFC 7233, "Range Not Satisfiable"
    public const EXPECTATION_FAILED = 417; // RFC 7231, "Expectation Failed
    public const IM_A_TEAPOT = 418; // RFC 7168, "I'm a teapot 
    public const UNPROCESSABLE_ENTITY = 422; // RFC 4918, WebDAV, "Unprocessable Entity"
    public const LOCKED = 423; // RFC 4918, "Locked"
    public const FAILED_DEPENDENCY = 424; // RFC 4918, "Failed Dependency"
    public const TOO_EARLY = 425; // RFC 8470, "Too Early"
    public const UPGRADE_REQUIRED = 426; // RFC 7231, "Upgrade Required"
    public const PRECONDITION_REQUIRED = 428; // RFC 6585, "Precondition Required"
    public const TOO_MANY_REQUESTS = 429; // RFC 6585, "Too Many Requests"
    public const REQUEST_HEADER_FIELDS_TOO_LARGE = 431; // RFC 6585, "Request Header Fields Too Large"           
    public const REQUEST_HEADER_TOO_LARGE = 494; // Nginx, "Request Header Too Large"
     public const CLIENT_CLOSED_REQUEST = 499; // Nginx, "Client Closed Request"
    public const UNAVAILABLE_FOR_LEGAL_REASONS = 451; // RFC 7725, "Unavailable For Legal Reasons"
   
    // 5xx Server Error
    public const INTERNAL_SERVER_ERROR = 500;
    public const NOT_IMPLEMENTED = 501;
    public const BAD_GATEWAY = 502; // RFC 7231, "Bad Gateway"
    public const SERVICE_UNAVAILABLE = 503;
    public const GATEWAY_TIMEOUT = 504;
    public const HTTP_VERSION_NOT_SUPPORTED = 505;
    public const VARIANT_ALSO_NEGOTIATES = 506; // RFC 2295, "Variant Also Negotiates"
    public const INSUFFICIENT_STORAGE = 507; // RFC 4918, WebDAV, "Insufficient Storage"
    public const LOOP_DETECTED = 508; // RFC 5842, WebDAV, "Loop Detected"
    public const NOT_EXTENDED = 510; // RFC 2774, "Not Extended"
    public const NETWORK_AUTHENTICATION_REQUIRED = 511; // RFC 6585, "Network Authentication Required"  
    public const NETWORK_READ_TIMEOUT_ERROR = 598; // RFC 2326, "Network Read Timeout Error"
    public const NETWORK_CONNECT_TIMEOUT_ERROR = 599; // RFC 2326, "Network Connect Timeout Error"
    public const UNKNOWN = 520; // Cloudflare, "Unknown Error"      
    public const WEB_SERVER_IS_DOWN = 521; // Cloudflare, "Web Server Is Down"
    public const CONNECTION_TIMED_OUT = 522; // Cloudflare, "Connection Timed Out"
    public const ORIGIN_IS_UNREACHABLE = 523; // Cloudflare, "Origin Is Unreachable"
    public const TIMEOUT_OCCURRED = 524; // Cloudflare, "Timeout Occurred"
    public const SSL_HANDSHAKE_FAILED = 525; // Cloudflare, "SSL Handshake Failed"
    public const INVALID_SSL_CERTIFICATE = 526; // Cloudflare, "Invalid SSL Certificate"
    public const SSL_CERTIFICATE_EXPIRED = 527; // Cloudflare, "SSL Certificate Expired"
    public const SSL_CERTIFICATE_REVOKED = 528; // Cloudflare, "SSL Certificate Revoked"
    public const NETWORK_READ_TIMEOUT = 598; // Nginx, "Network Read Timeout Error"
    public const NETWORK_CONNECT_TIMEOUT = 599; // Nginx, "Network Connect Timeout Error"
    public const UNKNOWN_ERROR = 520; // Cloudflare, "Unknown Error"

    /**
     * Prevent instantiation of this class
     */
    private function __construct() {}
    private function __clone() {}      
}