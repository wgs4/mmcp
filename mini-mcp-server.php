<?php

/*
 *        (( MMCP ))
 *      Mini-MCP Server
 *       version 1.0.0
 *
 * A simplified MCP Server for PHP
 *
 ********************************************************************************
 *
 * Copyright (c) 2025 Warehouse Guitar Speakers, LLC
 * 
 * Open sourced under the MIT License
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 ********************************************************************************
 *
 * Procedural PHP implementation of an HTTP-streamable MCP server
 * to standard HTTP/S port, via the endpoint of your choice
 * ("/mcp/" is common, default is "/".) The code is based on the
 * Python SDK FastMCP.
 *
 * Neither STDIO or SSE is supported with Mini-MCP, nor is OAuth.
 * Mini-MPC's tool definitions allow for titles, annotations,
 * and output schemas with their corresponding stuctured content
 * (as supported by protocol version 2025-06-18.) Note that both
 * output schemas and structured content will be dropped for
 * clients requesting an earlier version of the protocol. For
 * this reason, all tools with an output schema (and hence also
 * returning structured content) MUST also return unstructured
 * versions of that content.
 *
 * Does not implement resources, prompts, or any capabilities
 * beyond tools. Also does not implement the "list-changed"
 * capability for tools. And does not implement pagination
 * (see: https://modelcontextprotocol.io/specification/ ...
 *    ... 2025-03-26/server/utilities/pagination).
 *
 * To create your own custom server, create your server code file
 * and "include 'mini-mcp-server.php'" at the top of your file.
 * Be sure that every tool has both the tool function, and a
 * corresponding "mmcp_tool_registry_<funcname>" function to
 * "register" the tool (just returning the data necessary to
 * fulfill the "tools/list" request.) At the end of the file,
 * execute the "mmcp_main()" function.
 *
 * Also be sure to set $SERVER_NAME and $SERVER_VERSION, and
 * to set $MAX_CONNECTION_UPTIME to a new value if the default of
 * 24 hours isn't appropriate. (Also, $MCP_ACCESS_LOG_FILE,
 * $MCP_ERROR_LOG_FILE and $CONNECTION_TMP_DIR should also be
 * set again if the defaults aren't suitable.) Note that
 * connection data is maintained for a length of time past
 * closing equal to $MAX_CONNECTION_UPTIME.
 *
 * Connection data is stored in files in a temp folder. Each
 * connection is a single file; the name of the file is:
 * "<session-id>.json"
 *
 * The content of each connection file is a stringified JSON
 * object: {"status": <char>, "closed": <UNIX-time or 0>,
 *   "opened": <UNIX-time>, "client-info": <JSON-object>,
 *   "protocol": <protocol-version>}
 *
 * Code can be written for specialized endpoints, provided
 * the function to handle that endpoint, and requests to it,
 * is "registered" via "mmcp_endpoint_registry_<funcname>"
 * and coded. This function will be called before primary
 * processing functions, so these custom endpoints should
 * be able to detect and handle 'GET' requests (and should
 * return an error if 'GET' is not acceptable.) All output
 * from custom endpoints should be JSON.
 *
 *
 * TO-DO:
 *    - Add support for Resources, incl. embedded resources
 *      in tool call content -->
 *      https://modelcontextprotocol.io/specification/2025-06-18/server/tools
 *    - Add support for annotations (excludes earliest protocol version)
 *    - Add support for the 2024-11-05 version (and SSE-side events) -->
 *      https://modelcontextprotocol.io/specification/2024-11-05/basic/transports
 *
 *
 * Completed 2025-08-13 by RDJ (rodjacksonx@gmail.com, rod@wgsusa.com)
 *
 *
 */


// -------------------------- //
// ---------- INIT ---------- //
// -------------------------- //


// ---------- Server App Settings ---------- //

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ---------- Server Constants ---------- //

const TEST_API_ENDPOINT = 'https://wgsapi.com/xtuple-api/xtuple-test.php?apikey=';
const MMCP_DEFAULT_MCP_ENDPOINT = '/mini-mcp-server.php'; // should just be the path after the hostname

const ONE_DAY_IN_SECONDS = 86400; // 86,400 seconds = 1440 minutes = 24 hours
const ONE_MINUTE_IN_SECONDS = 60;

const MMCP_LOG_TIMESTAMP = 'Y-m-d H:i:s';

const MMCP_ERROR_TABLE = [
    0 => 'No error',
    1 => 'Minor inexplicable error',
    -32600 => 'Invalid Request',
    -32602 => 'Invalid Params' // but ignore pagination "cursor" params
];

const MMCP_SCHEMA_VERSION = 'https://json-schema.org/draft-07/schema#'; // 'https://json-schema.org/draft/2020-12/schema'

const MMCP_VALID_PROTOCOL_VERSIONS = [
    '2024-11-05', // requires an SSE endpoint; currently unsupported due to this
    '2025-03-26', // optional SSE endpoint, which we do not implement
    '2025-06-18' // optional SSE endpoint, which we do not implement
];
const MMCP_SUPPORTED_PROTOCOL_VERSIONS = [
    '2025-03-26',
    '2025-06-18'
];

const MMCP_EARLIEST_SUPPORTED_PROTOCOL_VERSION = MMCP_SUPPORTED_PROTOCOL_VERSIONS[0]; // current MMCP limit
const MMCP_LATEST_SUPPORTED_PROTOCOL_VERSION = MMCP_SUPPORTED_PROTOCOL_VERSIONS[1];
const MMCP_DEFAULT_PROTOCOL_VERSION = MMCP_LATEST_SUPPORTED_PROTOCOL_VERSION;

const MMCP_SID_HEADER_LABELS = [
    '2024-11-05' => "",
    '2025-03-26' => 'Mcp-Session-Id',
    '2025-06-18' => 'Mcp-Session-Id'
];
const MMCP_SID_HTTP_HEADER = 'HTTP_MCP_SESSION_ID';

const MMCP_PROTOCOL_HEADER_LABELS = [
    '2024-11-05' => "",
    '2025-03-26' => "",
    '2025-06-18' => 'MCP-Protocol-Version'
        // see -> https://modelcontextprotocol.io/specification/2025-06-18/basic/transports#protocol-version-header
];
const MMCP_PROTOCOL_HTTP_HEADER = 'HTTP_MCP_PROTOCOL_VERSION';

const MMCP_TOOL_REGISTRY_FUNCTION_PREFIX = 'mmcp_tool_registry_';
const MMCP_ENDPOINT_REGISTRY_FUNCTION_PREFIX = 'mmcp_endpoint_registry_';

const MMCP_CID_OPEN = 'O'; // connection is open and ready for use
const MMCP_CID_CLOSED = 'C'; // connection is closed and inaccessible
const MMCP_CID_INIT = 'I'; // in the process of being initialized

const MMCP_SERVER_NAME_DEFAULT = 'Mini MCP Server';
const MMCP_SERVER_VERSION_DEFAULT   = '1.0.0';

const MMCP_ACCESS_LOG_FILENAME = __DIR__ . '/mmcp-access.log';
const MMCP_ERROR_LOG_FILENAME = __DIR__ . '/mmcp-error.log';
const MMCP_DEBUG_LOG_FILENAME = __DIR__ . '/mmcp-debug.log';

const MMCP_CONNECTION_TMP_DIRECTORY = __DIR__ . '/mmcp-tmp';

/*const MMCP_DEFAULT_PROTOCOL_ERROR_OBJ = [
    'jsonrpc' => '2.0',
    'id' => 0,
    'error' => [
        'code' => 0,
        'message' => "",
        'data' => (new stdClass()) // optional; definitely used in initialization errors
            // see -> https://modelcontextprotocol.io/specification/2024-11-05/basic/lifecycle
    ]
];

const MMCP_DEFAULT_PING_RESPONSE_OBJ = [
    'jsonrpc' => '2.0',
    'id' => 0,
    'result' => (new stdClass()) // empty object, not empty indexed array
];*/

const MMCP_DEFAULT_INITIALIZE_RESPONSE_OBJ = [
    'jsonrpc' => '2.0',
    'id' => 0,
    'result' => [
        'protocolVersion' => MMCP_DEFAULT_PROTOCOL_VERSION, // currently '2025-06-18'
        'capabilities' => [
            'tools' => [
                'listChanged' => FALSE
            ]
        ],
        'serverInfo' => [
            'name' => MMCP_SERVER_NAME_DEFAULT,
            'version' => MMCP_SERVER_VERSION_DEFAULT
        ]
    ]
];

/*const MMCP_DEFAULT_INITIALIZE_ERROR_OBJ = [
    'jsonrpc' => '2.0',
    'id' => 0,
    'error' => [
        'code' => 0,
        'message' => "",
        'data' => (new stdClass())
    ]
];*/

const MMCP_DEFAULT_TOOL_LIST_RESPONSE_OBJ = [
    'jsonrpc' => '2.0',
    'id' => 0,
    'result' => [
        //'nextCursor' => 0, // only needed for paginations, which are unsupported
        'tools' => []
    ]
];

const MMCP_DEFAULT_TOOL_CALL_RESPONSE_OBJ = [
    'jsonrpc' => '2.0',
    'id' => 0,
    'result' => [
        'isError' => FALSE,
        'content' => []
    ]
];

const MMCP_DEFAULT_TOOL_CONTENT_OBJ = [
    'type' => 'text',
    'text' => ""
];

const MMCP_DEFAULT_TOOL_TEXT_CONTENT_OBJ = MMCP_DEFAULT_TOOL_CONTENT_OBJ;

const MMCP_DEFAULT_TOOL_IMAGE_CONTENT_OBJ = [
    'type' => 'image',
    'data' => "", // base64-encoded-image-data
    'mimeType' => 'image/jpg'
];

const MMCP_DEFAULT_TOOL_AUDIO_CONTENT_OBJ = [
    'type' => 'audio',
    'data' => "", // base64-encoded-audio-data
    'mimeType' => 'audio/wav'
];

// ---------------- Functions-as-Constants ------------------ //

// These are necessary because older versions of PHP
// do not allow objects to be elements of constants

function MMCP_DEFAULT_PROTOCOL_ERROR_OBJ()
{
    return [
        'jsonrpc' => '2.0',
        'id' => 0,
        'error' => [
            'code' => 0,
            'message' => "",
            'data' => (new stdClass()) // optional; definitely used in initialization errors
                // see -> https://modelcontextprotocol.io/specification/2024-11-05/basic/lifecycle
        ]
    ];
}

function MMCP_DEFAULT_PING_RESPONSE_OBJ()
{
    return [
        'jsonrpc' => '2.0',
        'id' => 0,
        'result' => (new stdClass()) // empty object, not empty indexed array
    ];
}

function MMCP_DEFAULT_INITIALIZE_ERROR_OBJ()
{
    return [
        'jsonrpc' => '2.0',
        'id' => 0,
        'error' => [
            'code' => 0,
            'message' => "",
            'data' => (new stdClass())
        ]
    ];
}


// ----------------- Global Vars ------------------ //

$MMCP_MCP_ENDPOINT = MMCP_DEFAULT_MCP_ENDPOINT;

$MMCP_MAX_CONNECTION_UPTIME = ONE_DAY_IN_SECONDS;
$MMCP_REQUEST_TIMEOUT = ONE_MINUTE_IN_SECONDS; // mainly used for initialization confirmation

$MMCP_SERVER_NAME = MMCP_SERVER_NAME_DEFAULT;
$MMCP_SERVER_VERSION = MMCP_SERVER_VERSION_DEFAULT;

$MMCP_ACCESS_LOG_FILE = MMCP_ACCESS_LOG_FILENAME;
$MMCP_ERROR_LOG_FILE = MMCP_ERROR_LOG_FILENAME;
$MMCP_DEBUG_LOG_FILE = MMCP_DEBUG_LOG_FILENAME;

$MMCP_CONNECTION_TMP_DIR = MMCP_CONNECTION_TMP_DIRECTORY;

$MMCP_CURRENT_PROTOCOL_VERSION = MMCP_DEFAULT_PROTOCOL_VERSION;


// --------------------------- //
// ---------- FUNCS ---------- //
// --------------------------- //

// ---------- Accessory Functions ---------- //

// construct the filename for a connection file
function mmcp_construct_connection_filename($cid)
{
    return $cid . '.json';
}

// extract the connection ID from the connection filename
// (NOT including the entire path, just the filename)
function mmcp_pull_cid_from_connection_fn($fn)
{
    return str_replace('.json', "", $fn);
}

// closes a connection; returns prior
// status of connection, or empty string
// if the connection couldn't be verified
function mmcp_close_connection($cid)
{
   return mmcp_update_connection($cid, MMCP_CID_CLOSED);
}

// actually sends the HTTP response,
// flushing out buffers afterwards
function mmcp_send_response($txt)
{
    echo($txt);
    flush();
    //if (function_exists('ob_flush'))
        //ob_flush();
}

// verifies that the connection exists and
// hasn't been closed; if so, returns
// connection data as an associative array,
// otherwise returns a boolean FALSE
function mmcp_verify_connection($cid)
{
    $data = mmcp_get_connection_data($cid);

    // if it doesn't exist, FALSE
    if (empty($data))
        return FALSE;

    // if it's closed, FALSE
    if (($data['closed'] != 0) and ($data['status'] === MMCP_CID_CLOSED))
        return FALSE;

    return $data; 
}

// update the status of a connection;
// returns prior status, or empty string
// if could not verify connection
function mmcp_update_connection($cid, $status)
{
    global $MMCP_CONNECTION_TMP_DIR;

    $prior_status = "";

    $data = mmcp_verify_connection($cid);
    if ($data)
    {
        $prior_status = $data['status'];
        $data['status'] = $status;
        if ($status === MMCP_CID_CLOSED)
            $data['closed'] = time();
        $fn = mmcp_construct_connection_filename($cid);
        file_put_contents($MMCP_CONNECTION_TMP_DIR . '/' . $fn, json_encode($data));
    }

    return $prior_status;
}

// sets up the initial headers to be sent for the HTTP
// response, BEFORE anything is relayed to the client
function mmcp_establish_headers($sid = "", $pv = "")
{
    // CORS headers for browser access
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Accept, Mcp-Session-Id, MCP-Protocol-Version, Authorization');

    // protocol headers
    header('Content-type: application/json');

    if (!empty($pv))
    {
        $debug_line = "";

        $header_label = MMCP_SID_HEADER_LABELS[$pv];
        if ((!empty($sid)) and (!empty($header_label)))
        {
            $extra_header = $header_label . ': ' . $sid;
            header($extra_header);
            $debug_line .= "SID Header: '$extra_header' ";
        }

        $header_label = MMCP_PROTOCOL_HEADER_LABELS[$pv];
        if (!empty($header_label))
        {
            $extra_header = $header_label . ': ' . $pv;
            header($extra_header);
            $debug_line .= "PROTOCOL Header: '$extra_header'";
        }

       mmcp_log_debug($debug_line);
    }
}

// records every access to the server, both requests
// and responses; returns the result of the write
// (protocol errors sent should use mmcp_log_error)
function mmcp_log_access($cid, $rid, $r, $is_request = FALSE)
{
    global $MMCP_ACCESS_LOG_FILE;

    if ($is_request)
        $type = 'REQ ';
    else
        $type = 'RESP';

    if (($cid === "") or ($cid === null))
        $cid = "--------------N/A---------------"; // same length as session ID

    if (($rid === "") or ($rid === null))
        $rid = "N/A";
    else
        $rid = str_pad($rid, 3, ' ', STR_PAD_LEFT);

    $ts = date(MMCP_LOG_TIMESTAMP);
    $logline = "$ts >> $type [CID:$cid/RID:$rid] => $r".PHP_EOL;

    $bytes_written = file_put_contents($MMCP_ACCESS_LOG_FILE, $logline, FILE_APPEND | LOCK_EX);

    return $bytes_written;
}

// records debug info
function mmcp_log_debug($data)
{
    global $MMCP_DEBUG_LOG_FILE;

    $ts = date(MMCP_LOG_TIMESTAMP);
    $logline = "$ts >> $data".PHP_EOL;

    $bytes_written = file_put_contents($MMCP_DEBUG_LOG_FILE, $logline, FILE_APPEND | LOCK_EX);

    return $bytes_written;
}

// returns either the stored data of an
// existing connection, or a boolean FALSE
// if the connection file doesn't exist
// or cannot be read
function mmcp_get_connection_data($cid, $got_filename = FALSE)
{
    global $MMCP_CONNECTION_TMP_DIR;

    if (!$got_filename)
        $fn = mmcp_construct_connection_filename($cid);
    else
        $fn = $got_filename;

    $fullpath = $MMCP_CONNECTION_TMP_DIR . '/' . $fn;

    if (!file_exists($fullpath))
        return FALSE;

    $data = array();
    $jsonobj_str = file_get_contents($MMCP_CONNECTION_TMP_DIR . '/' . $fn);

    if ($jsonobj_str === FALSE)
        return FALSE;

    $jsonobj = json_decode($jsonobj_str, TRUE); // associative array

    // basic validation
    if (($jsonobj === null) or (!is_array($jsonobj)))
       return FALSE;
    if ((!isset($jsonobj['opened'])) or
        (!isset($jsonobj['status'])) or
        (!isset($jsonobj['closed'])) or
        (!isset($jsonobj['client-info'])) or
        (!isset($jsonobj['protocol'])))
        return FALSE;

    $data['opened'] = $jsonobj['opened'];
    $data['status'] = $jsonobj['status'];
    $data['closed'] = $jsonobj['closed'];
    $data['client-info'] = $jsonobj['client-info'];
    $data['protocol'] = $jsonobj['protocol'];

    return $data;
}

// records all protocol errors sent to the client;
// expects a JSON-ified string of the error object
function mmcp_log_error($json_response)
{
    global $MMCP_ERROR_LOG_FILE;

    $ts = date(MMCP_LOG_TIMESTAMP);
    $logline = "$ts => $json_response".PHP_EOL;

    $bytes_written = file_put_contents($MMCP_ERROR_LOG_FILE, $logline, FILE_APPEND | LOCK_EX);

    return $bytes_written;
}

// constructs an JSON-ified array of all custom
// endpoints supported by the server; all custom
// endpoints must have a custom function (cfunc)
// to handle incoming requests (whether 'GET' or
// 'POST', as both are allowed for them,) and
// must also have a registry function that
// informs the system of the endpoint's existence
// (the registry function is the cfunc name
// prefixed by the text of
// MMCP_ENDPOINT_REGISTRY_FUNCTION_PREFIX.)
function mmcp_custom_endpoint_list()
{
    // init
    $all_endpoints = array();
    $prefix_len = strlen(MMCP_ENDPOINT_REGISTRY_FUNCTION_PREFIX);

    // find all existing functions whose names
    // start with the registry prefix, looping
    // through them to extract the suffixed
    // endpoint function as well as the
    // associated endpoint
    $all_funcs = get_defined_functions();
    $all_user_funcs = $all_funcs['user'];
    foreach ($all_user_funcs as $user_func)
    {
        // make sure func name matches the prefix
        if (strpos($user_func, MMCP_ENDPOINT_REGISTRY_FUNCTION_PREFIX) === 0)
        {
            // but also has to be longer than the prefix
            if (strlen($user_func) > $prefix_len)
            {
                $endpoint_func = substr($user_func, $prefix_len);
                $registry_func = $user_func;
                $endpoints = $registry_func();
                if (!is_array($endpoints))
                    $all_endpoints[$endpoint] = $endpoint_func;
                else
                    foreach ($endpoints as $endpoint)
                        $all_endpoints[$endpoint] = $endpoint_func;
            }
        }
    }

    // return the final result
    return $all_endpoints;
}

// determines if a given endpoint is
// or is not one that the server is
// capable of handling; returns either
// a boolean FALSE or the name of the
// associated endpoint function
function mmcp_custom_endpoint_exists($endpoint)
{
    $endpoint_list = mmcp_custom_endpoint_list();

    if (!isset($endpoint_list[$endpoint]))
        return FALSE;
    else
        return $endpoint_list[$endpoint];
}

// will attempt to parse and validate a request;
// returns an array with two elements, 'data'
// which will contain either the parsed request
// as an associative array, or whatever JSON was
// encoded, and 'error', which is the error code
// of an invalid request, or 0 if no error.
// Expects to be given the text of a JSON object
// or datum. If the datum is null or if it cannot
// be parsed as JSON, NULL is returned.
function mmcp_validate_request($request)
{
    $r = json_decode($request, TRUE); // TRUE ensures an associative array

    // validate that it's parseable JSON
    if ($r === NULL)
       return NULL;

    // begin constructing result
    $result = [ 'data' => $r, 'error' => 0 ];

    // validate JSON-RPC structure
    if (!is_array($r))
    {
        $result['error'] = -32600; // invalid request (invalid JSON-RPC structure)
    }
    elseif (!isset($r['jsonrpc']) || $r['jsonrpc'] !== '2.0')
    {
        $result['error'] = -32600; // invalid request (invalid JSON-RPC structure)
    }
    // validate method
    elseif ((!isset($r['method'])) or (empty($r['method'])))
    {
        $result['error'] = -32600; // invalid request (missing method)
    }
    // depending on circumstance, id and/or params
    // may not be required; since id can be a string,
    // just ensure params, if it exists, is an object
    elseif ((isset($r['params'])) and (!is_array($r['params'])))
    {
        $result['error'] = -32600; // invalid request (bad params)
    }

    // we have our result to return
    return $result;
}

// accesses the $_SERVER global to extract
// the current thread's connection ID;
// returns either the connection ID as
// communicated from the client via the
// Mcp-Session-Id header, or else FALSE
function mmcp_extract_connection_id()
{
    $id = FALSE;

    if (isset($_SERVER['HTTP_MCP_SESSION_ID']))
        $id = $_SERVER['HTTP_MCP_SESSION_ID'];

    return $id;
}


// ---------- Minor Functions ---------- //

// clears out connections that are expired
// (they exist and were started two connection
// uptimes ago or more) by deleting the files;
// also closes connections that are past the
// connection uptime max, or have been
// waiting past the timeout for initialization
// confirmation
function mmcp_clear_old_connections()
{
    global $MMCP_CONNECTION_TMP_DIR, $MMCP_MAX_CONNECTION_UPTIME, $MMCP_REQUEST_TIMEOUT;

    $connections = mmcp_get_all_connections();

    foreach ($connections as $cid)
    {
        $now = time();
        $fn = mmcp_construct_connection_filename($cid);
        $data = mmcp_get_connection_data($cid, $fn);

        if (!empty($data))
        {
            $opened = $data['opened'];
            $closed = $data['closed'];
            $status = $data['status'];

            $earliest_allowed = $now - (2 * $MMCP_MAX_CONNECTION_UPTIME);
            $should_be_expired = ($opened < $earliest_allowed);

            // if connection's so old it should be expired, not just closed, kill it
            if ($should_be_expired)
                unlink($MMCP_CONNECTION_TMP_DIR . '/' . $fn);
            elseif ($status === MMCP_CID_INIT) // if in process of initializing still
            {
                if ($opened < ($now - $MMCP_REQUEST_TIMEOUT))
                    unlink($MMCP_CONNECTION_TMP_DIR . '/' . $fn);
            }
            elseif (($closed !== 0) or ($status !== MMCP_CID_CLOSED)) // not marked closed
            {
                if ($opened < ($now - $MMCP_MAX_CONNECTION_UPTIME))
                    mmcp_close_connection($cid);
            }
        }
    }
}

// send a protocol error specifically from initialization
function mmcp_send_init_error($rid, $code, $message, $data)
{
    $response = MMCP_DEFAULT_INITIALIZE_ERROR_OBJ();
    $response['id'] = $rid;
    $response['error']['code'] = $code;
    $response['error']['message'] = $message;
    $response['error']['data'] = $data;

    $json_response = json_encode($response);
    mmcp_send_error($json_response);
}

// send a generic protocol error message; expects
// a JSON-ified string of the error object
function mmcp_send_error($json_error)
{
    mmcp_send_response($json_error);
    mmcp_log_error($json_error);
}

// gets all current connections (including
// closed-but-not-removed ones,) specifically
// returning an array of all connection IDs
function mmcp_get_all_connections()
{
    global $MMCP_CONNECTION_TMP_DIR;

    // ensure directory exists
    if (!is_dir($MMCP_CONNECTION_TMP_DIR))
        mkdir($MMCP_CONNECTION_TMP_DIR, 0700, true); // octal notation

    $pattern = $MMCP_CONNECTION_TMP_DIR . '/*';
    $files = glob($pattern);
    $connections = [];

    if (!empty($files))
    {
        foreach ($files as $filepath)
        {
            $fn = basename($filepath);
            $cid = mmcp_pull_cid_from_connection_fn($fn);
            $connections[] = $cid;
        }
    }

    return $connections;
}

// given either a valid endpoint, or the name of
// an endpoint function, calls the appropriate
// function to handle a request
function mmcp_call_custom_endpoint($endpoint_or_func)
{
    // check if it's an endpoint or the func; we need the func
    if (function_exists($endpoint_or_func))
        $func = $endpoint_or_func;
    else
    {
        $endpoint_list = mmcp_custom_endpoint_list();
        $func = $endpoint_list[$endpoint_or_func];
    }

    $func($_SERVER['REQUEST_METHOD']);
}


// ---------- Primary Functions ---------- //

// feeds the passed in parameters to the appropriate
// tool function, then allows it construct a response
// which it then returns to the client; the result
// should not be paginated, and will be adjusted to
// fit the protocol version (for example, no
// constructed output if not 2025-06-18 or above) and
// to remove annotations (NOTE: the tool name must
// match the actual name of the tool function, AND
// this function presumes the tool func exists)
function mmcp_handle_tool_call($toolname, $pv, $params)
{
    // actually call the tool, get a response
    $response = $toolname($params);

    // adjust based on protocol version
    $response = mmcp_adjust_tool_result_to_protocol($response, $pv);

    // finally, return the response
    return $response;
}

// adjust the tool response output to the current protocol version
function mmcp_adjust_tool_result_to_protocol($result, $pv)
{
    // init
    $modified_result = $result;

    // for now, just remove any structured content
    // if the PV is before 2025-06-18
    if ($pv < '2025-06-18')
    {
        if (isset($result['structuredContent']))
            unset($modified_result['structuredContent']);
    }

    // return the finessed result
    return $modified_result;
}

// constructs an JSON-ified array of all tools
// supported by the server; if the protocol
// version given allows, this may include titles
// and/or output schemas (if not supported by
// the protocol, these will be dropped even if
// they exist in the tool definitions provided
// by the functions. All tools must have a
// custom function (cfunc) to handle calls, and
// must also have a registry function that
// returns the function's definition (and that
// is the cfunc name prefixed by the text of
// MMCP_TOOL_REGISTRY_FUNCTION_PREFIX.)
function mmcp_handle_tool_list($rid, $pv, $cursor = "")
{
    // init
    $tools = array();
    $prefix_len = strlen(MMCP_TOOL_REGISTRY_FUNCTION_PREFIX);

    // find all existing functions whose names
    // start with the registry prefix, looping
    // through them to extract the suffixed
    // function name as well as the returned
    // tool definition (trimmed as per protocol)
    $all_funcs = get_defined_functions();
    $all_user_funcs = $all_funcs['user'];
    foreach ($all_user_funcs as $user_func)
    {
        // make sure func name matches the prefix
        if (strpos($user_func, MMCP_TOOL_REGISTRY_FUNCTION_PREFIX) === 0)
        {
            // but also has to be longer than the prefix
            if (strlen($user_func) > $prefix_len)
            {
                $tool_func = substr($user_func, $prefix_len);
                $registry_func = $user_func;
                $tool_def = $registry_func(); // tool defs should include tool "name"
                $tool_def = mmcp_adjust_tool_def_to_protocol($tool_def, $pv);
                $tools[] = $tool_def;
            }
        }
    }

    // combine all the tool definitions into
    // one appropriate for the protocol version
    $response = MMCP_DEFAULT_TOOL_LIST_RESPONSE_OBJ;
    $response['id'] = $rid;
    $response['result']['tools'] = $tools;

    // return the final result
    return $response;
}

// adjusts a tool definition to fit into the specified protocol
function mmcp_adjust_tool_def_to_protocol($def, $pv)
{
    // init
    $modified_def = $def;

    // main change that matters currently is that
    // anything below 2025-06-18 should drop any
    // titles and/or output schemas
    if ($pv < '2025-06-18')
    {
        if (isset($def['title']))
            unset($modified_def['title']);
 
        if (isset($def['outputSchema']))
            unset($modified_def['outputSchema']);
    }

    // return the tool definition
    return $modified_def;
}

// expects a valid request as a JSON-ified array
function mmcp_handle_initialize($request)
{
    // globals
    global $MMCP_CONNECTION_TMP_DIR, $MMCP_CURRENT_PROTOCOL_VERSION;
    global $MMCP_SERVER_NAME, $MMCP_SERVER_VERSION;

    // inits
    $success = TRUE;

    // client must NOT send a Mcp-Session-Id header during initialization
    if (isset($_SERVER['HTTP_MCP_SESSION_ID']))
        return FALSE;

    // verify that the request is sound
    if (!isset($request['id']))
        return FALSE;

    if (!isset($request['params']))
        return FALSE;

    if ((!isset($request['params']['protocolVersion'])) or (empty($request['params']['protocolVersion'])))
        return FALSE;

    if ((!isset($request['params']['clientInfo'])) or (empty($request['params']['clientInfo'])))
        return FALSE;

    // validate the protocol version
    $pv = $request['params']['protocolVersion'];
    if (!in_array($pv, MMCP_VALID_PROTOCOL_VERSIONS))
        return FALSE;

    // negotiate the protocol version (if client
    // only accepts ones older than ones MMCP
    // supports, just use our earliest, and they
    // can disconnect if they can't handle it
    if (($pv < MMCP_EARLIEST_SUPPORTED_PROTOCOL_VERSION) or ($pv > MMCP_LATEST_SUPPORTED_PROTOCOL_VERSION))
    {
        $versions = MMCP_SUPPORTED_PROTOCOL_VERSIONS;
        $data = [ 'supported' => $versions, 'requested' => '1.0.0' ];
        mmcp_send_init_error($request['id'], -32602, "Unsupported protocol version", $data);
        return FALSE;
    }
    else
        $server_pv = $pv;

    // acknowledge the protocol version
    $MMCP_CURRENT_PROTOCOL_VERSION = $server_pv;

    // generate the session ID & establish headers
    $sid = bin2hex(random_bytes(16)); //session_id();
    session_id($sid);
    session_start();
    mmcp_establish_headers($sid, $server_pv);

    // setup the connection file
    $fn = mmcp_construct_connection_filename($sid);
    $fullpath = $MMCP_CONNECTION_TMP_DIR . '/'. $fn;
    $now = time();
    $client_info = $request['params']['clientInfo'];
    $rid = $request['id']; // request ID
    $connection_data = [
        'status' => 'I', 'opened' => $now, 'closed' => 0, 'client-info' => $client_info,
        'protocol' => $server_pv ];
    $bytes = file_put_contents($fullpath, json_encode($connection_data));
    if (empty($bytes))
        return FALSE;

    // construct the response
    $response = MMCP_DEFAULT_INITIALIZE_RESPONSE_OBJ;
    $response['id'] = $rid;
    $response['result']['serverInfo']['name'] = $MMCP_SERVER_NAME;
    $response['result']['serverInfo']['version'] = $MMCP_SERVER_VERSION;
    $response['result']['protocolVersion'] = $server_pv;
    //$response['result']['connectionFullpath'] = $fullpath; // for debugging
    //$response['result']['connectionData'] = $connection_data; // for debugging

    // send the response
    http_response_code(200); // request accepted and successfully processed
    $json_response = json_encode($response);
    mmcp_send_response($json_response);
    mmcp_log_access($sid, $rid, $json_response, false);

    // return T or F based on
    // whether everything is good
    return $success;
}

// given a seemingly-valid request, and the connection ID,
// processes the request as appropriate to the main endpoint;
// handles pings, notifications, tool/call and tool/list
function mmcp_process_request($request, $cid)
{
    global $MMCP_CURRENT_PROTOCOL_VERSION;

    // Extract core request components
    $rid = $request['id'] ?? null;
    $method = $request['method'];
    $params = $request['params'] ?? [];

    // Set up proper headers for the response
    mmcp_establish_headers($cid, $MMCP_CURRENT_PROTOCOL_VERSION);

    // Route to proper function based on method

    // ping
    if ($method == 'ping')
    {
        $response = mmcp_handle_ping($cid, $rid);
        $json_response = json_encode($response);
        mmcp_send_response($json_response);
        mmcp_log_access($cid, $rid, $json_response, false);
    }

    // tools/list
    elseif ($method =='tools/list')
    {
        // Get the list of available tools
        // Check for optional cursor parameter (for pagination, though we don't support it)
        $cursor = $params['cursor'] ?? null;
        if ($cursor !== null)
        {
            // We don't support pagination, return error
            $response = MMCP_DEFAULT_PROTOCOL_ERROR_OBJ();
            $response['id'] = $rid;
            $response['error']['code'] = -32602;
            $response['error']['message'] = 'Pagination not supported. Do not include cursor parameter.';
        }
        else
        {
            // just get the tool list
            $response = mmcp_handle_tool_list($rid, $MMCP_CURRENT_PROTOCOL_VERSION); // no cursor
        }

        $json_response = json_encode($response);
        if (isset($response['error']))
            mmcp_send_error($json_response);
        else
            mmcp_send_response($json_response);

        // can match a request, so log it as a response too
        mmcp_log_access($cid, $rid, $json_response, false);
    }

    // tools/call
    elseif ($method == 'tools/call')
    {
        // Validate required parameters
        if (!isset($params['name']) || empty($params['name']))
        {
            $response = MMCP_DEFAULT_PROTOCOL_ERROR_OBJ();
            $response['id'] = $rid;
            $response['error']['code'] = -32602;
            $response['error']['message'] = 'Missing required parameter: name';
        }
        // we at least have params
        else
        {
            // pull tool specifics
            $tool_name = $params['name'];
            $tool_params = $params['arguments'] ?? [];

            // Check if the tool exists (tool name and tool func should match)
            $tool_func = $tool_name;
            if (!function_exists($tool_func))
            {
                $response = MMCP_DEFAULT_PROTOCOL_ERROR_OBJ();
                $response['id'] = $rid;
                $response['error']['code'] = -32602;
                $response['error']['message'] = "Unknown tool: $tool_name";
            }
            // it exists; call the tool and get the result
            else
            {
                $result = mmcp_handle_tool_call($tool_name, $MMCP_CURRENT_PROTOCOL_VERSION, $tool_params);
                $response = MMCP_DEFAULT_TOOL_CALL_RESPONSE_OBJ;
                $response['id'] = $rid;
                $response['result'] = $result;
            }
        }

        // send the response
        $json_response = json_encode($response);
        if (isset($response['error']))
            mmcp_send_error($json_response);
        else
            mmcp_send_response($json_response);

        // can match a request, so log it as a response too
        mmcp_log_access($cid, $rid, $json_response, false);
    }

    // notifications
    elseif (strpos($method, 'notifications/') === 0)
    {
        // handle the notification (likely doing nothing)
        $response = mmcp_handle_notification($method, $params, $cid, $rid);

        // notifications don't send a response
        // other than an HTTP 202 OK if all good,
        // or some other value if an error
        if ($response === 202)
            http_response_code($response);
    }

    // other (invalid method)
    else
    {
        // Unknown method
        $response = MMCP_DEFAULT_PROTOCOL_ERROR_OBJ();
        $response['id'] = $rid;
        $response['error']['code'] = -32601;  // Method not found
        $response['error']['message'] = "Unknown method: $method";

        $json_response = json_encode($response);
        mmcp_send_error($json_response);
        // can match a request, so log it as a response too
        mmcp_log_access($cid, $rid, $json_response, false);
    }

    // finished with main inner function!
    return;

} // end mmcp_process_request()

// respond appropriately to a client-sent ping
function mmcp_handle_ping($cid, $rid)
{
    // Ping just returns an empty object
    $response = MMCP_DEFAULT_PING_RESPONSE_OBJ();
    $response['id'] = $rid;

    return $response;
}

// respond appropriately (internally-only) to a client-sent notification
// (excluding "notifications/initialized", as that's handled higher up)
function mmcp_handle_notification($method, $params, $cid, $rid)
{
    // incoming notifications have already been logged,
    // so just ignore the processing, debug and return
    // 202 to indicate to just signal "accepted"
    $data = "Notification headers: ".json_encode($_SERVER);
    mmcp_log_debug($data);
    return 202;
}


// -------------------------- //
// ---------- MAIN ---------- //
// -------------------------- //

function mmcp_main()
{
    global $MMCP_MCP_ENDPOINT, $MMCP_ACCESS_LOG_FILE, $MMCP_ERROR_LOG_FILE, $MMCP_CURRENT_PROTOCOL_VERSION;

    // First, clean up old connections
    mmcp_clear_old_connections();

    // Get the request method, URI and any connection ID
    $method = $_SERVER['REQUEST_METHOD'];
    $request_uri = $_SERVER['REQUEST_URI'];
    $cid = mmcp_extract_connection_id();


    // Remove query string if present, we only need the path for endpoints
    $uri_parts = parse_url($request_uri);
    $endpoint_path = $uri_parts['path'];
    $endpoint = str_replace($MMCP_MCP_ENDPOINT, "", $endpoint_path) ?? '/';

    // Handle OPTIONS requests for CORS
    if ($method === 'OPTIONS')
    {
        mmcp_establish_headers();
        http_response_code(204); // No Content
        exit();
    }

    // Normalize the core MCP endpoint - handle variations
    $core_endpoint_path = $MMCP_MCP_ENDPOINT;
    $core_endpoint = str_replace($MMCP_MCP_ENDPOINT, "", $core_endpoint_path) ?? '/';
    $core_endpoint_normalized = rtrim($core_endpoint, '/');

    // Create array of acceptable core endpoint variations
    $core_variations = [
        $core_endpoint_normalized,                // without trailing slash
        $core_endpoint_normalized . '/',          // with trailing slash
        $core_endpoint_normalized . '/mcp',       // with /mcp
        $core_endpoint_normalized . '/mcp/',      // with /mcp/
    ];

    // Check if the request is to a core or custom endpoint
    $is_core_endpoint = in_array($endpoint, $core_variations);
    if (!$is_core_endpoint)
    {
        // Check if this is a custom endpoint
        $custom_func = mmcp_custom_endpoint_exists($endpoint);
        if ($custom_func !== false)
            // Call the custom endpoint handler
            mmcp_call_custom_endpoint($custom_func);
        else
        {
            // Unknown endpoint
            http_response_code(404); // Not Found
            $error = ['error' => '404 Not Found - Unknown endpoint', 'endpoint' => $endpoint];
            mmcp_send_error(json_encode($error));
        }

        exit();
    }

    // This is a core endpoint MCP protocol request; carry on
    // ------------------------------------------------------

    // Handle DELETE requests to close connections
    if ($method === 'DELETE')
    {
        if (!$cid)
        {
            http_response_code(400); // Bad Request
            $error = ['error' => 'No session to close'];
            mmcp_send_error(json_encode($error));
            exit();
        }
            
        // Close the connection
        $prior_status = mmcp_close_connection($cid);
            
        if ($prior_status)
        {
            http_response_code(200); // OK
            $response = ['status' => 'closed', 'session' => $cid];
            mmcp_log_access($cid, null, 'DELETE request - connection closed', true);
            $json_response = json_encode($response);
            mmcp_send_response($json_response);
            mmcp_log_access($cid, null, $json_response, false);
        }
        else
        {
            http_response_code(404); // Not Found
            $error = ['error' => 'Session not found or already closed'];
            mmcp_send_error(json_encode($error));
        }
        exit();
    }

    // We already did CORS preflight handling,
    // and handling of DELETE to close connections;
    // the core endpoint should only accept POST
    if ($method !== 'POST')
    {
        // if it's a GET, record what the content was; some MCP clients send this
        /*if ($method == 'GET')
        {
            $error = ['error' => "Client sent a GET. See 'server-data' for more details."];
            $error['server-data'] = $_SERVER;
            mmcp_log_error(json_encode($error));

            http_response_code(200);
            header('Allow: POST, GET, DELETE');
            $endpoint = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['SERVER_NAME'] . $request_uri;
            $event = "event: endpoint\ndata: $endpoint\n";
            //header('Content-Type: application/json');
            //$status = json_encode([ 'status' => 'ok' ]);
            mmcp_send_response($event);
            exit();
        }*/

        // MCP protocol only accepts POST requests
        http_response_code(405); // Method Not Allowed
        header('Allow: POST, DELETE');
        $error = ['error' => "Method '$method' not allowed. MCP protocol requires POST requests."];
        mmcp_send_error(json_encode($error));
        exit();
    }

    // Read the request body
    $raw_input = file_get_contents('php://input');
    if (empty($raw_input))
    {
        http_response_code(400); // Bad Request
        $error = ['error' => 'Empty request body'];
        mmcp_send_error(json_encode($error));
        exit();
    }

    // Validate the request first
    $validation = mmcp_validate_request($raw_input);

    // ensure it's at least parseable JSON
    if ($validation === null)
    {
        // Invalid JSON - log the raw input since we can't parse it
        mmcp_log_access($cid, null, $raw_input, true);

        // Send error response
        http_response_code(400); // Bad Request
        $error = ['error' => 'Invalid JSON in request body'];
        $json_error = json_encode($error);
        mmcp_send_error($json_error);
        exit();
    }

    // Extract request ID if available (even from invalid requests)
    $rid = $validation['data']['id'] ?? null;

    // Log the valid JSON request with its ID
    mmcp_log_access($cid, $rid, json_encode($validation['data']), true);

    // If not a valid request, respond appropriately
    if ($validation['error'] !== 0) {
        // Invalid request structure
        http_response_code(400); // Bad Request
        $error_code = $validation['error'];
        $error_msg = MMCP_ERROR_TABLE[$error_code] ?? 'Unknown error';

        $response = MMCP_DEFAULT_PROTOCOL_ERROR_OBJ();
        $response['id'] = $rid;
        $response['error']['code'] = $error_code;
        $response['error']['message'] = $error_msg;

        $json_response = json_encode($response);
        mmcp_send_error($json_response);
        exit();
    }

    // Process the valid request
    $request = $validation['data'];
    $request_id = $rid;
    $request_method = $request['method'];

    // Special handling for initialization - no session required
    if ($request_method === 'initialize')
    {
        $success = mmcp_handle_initialize($request);

        // If error, it was already sent by mmcp_handle_initialize;
        // either way, just exit immediately 'cause we're done here
        exit();
    }

    // All other requests require a valid session
    if (!$cid)
    {
        http_response_code(400); // Bad Request
        $response = MMCP_DEFAULT_PROTOCOL_ERROR_OBJ();
        $response['id'] = $request_id;
        $response['error']['code'] = -32600;
        $response['error']['message'] = 'Missing session ID. Initialize connection first.';

        $json_response = json_encode($response);
        mmcp_send_error($json_response);
        mmcp_log_debug("Non-initialize request sent without session id: ".json_encode($request).
            "\n >>> Request headers: ".json_encode($_SERVER));
        exit();
    }

    // Verify the connection exists
    $connection = mmcp_verify_connection($cid);
    if (!$connection)
    {
        http_response_code(400); // Bad Request
        $response = MMCP_DEFAULT_PROTOCOL_ERROR_OBJ();
        $response['id'] = $request_id;
        $response['error']['code'] = -32600;
        $response['error']['message'] = 'Invalid or closed session.';

        $json_response = json_encode($response);
        mmcp_send_error($json_response);
        exit();
    }

    // Check protocol version consistency (only needed for 2025-06-18)
    $MMCP_CURRENT_PROTOCOL_VERSION = $connection['protocol'];
    if ($MMCP_CURRENT_PROTOCOL_VERSION == '2025-06-18')
    {
        // we're demanding the protocol version be sent by the client
        $client_pv = $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? null;
        if (!$client_pv)
        {
            // Missing required protocol version header
            http_response_code(400); // Bad Request
            $response = MMCP_DEFAULT_PROTOCOL_ERROR_OBJ();
            $response['id'] = $request_id;
            $response['error']['code'] = -32600;
            $response['error']['message'] = 'Missing required MCP-Protocol-Version header for negotiated protocol 2025-06-18.';

            $json_response = json_encode($response);
            mmcp_send_error($json_response);
            // can match a request, so log it as a response too
            mmcp_log_access($cid, $request_id, $json_response, false);
            exit();
        }

        // protocol version must also match
        if ($client_pv !== $MMCP_CURRENT_PROTOCOL_VERSION)
        {
            // Protocol version mismatch
            http_response_code(400); // Bad Request
            $response = MMCP_DEFAULT_PROTOCOL_ERROR_OBJ();
            $response['id'] = $request_id;
            $response['error']['code'] = -32600;
            $response['error']['message'] = "Protocol version mismatch. Expected: $MMCP_CURRENT_PROTOCOL_VERSION, received: $client_pv.";

            $json_response = json_encode($response);
            mmcp_send_error($json_response);
            // can match a request, so log it as a response too
            mmcp_log_access($cid, $request_id, $json_response, false);
            exit();
        }
    }

    // Special handling for the initialized notification
    if ($request_method === 'notifications/initialized')
    {
        // This notification confirms the client has processed initialization
        if ($connection['status'] === MMCP_CID_INIT)
        {
            mmcp_update_connection($cid, MMCP_CID_OPEN);
            // Process the notification only if we're waiting for it
            mmcp_process_request($request, $cid);
        }

        // Ignore the notification if we're not waiting on it
        exit();
    }

    // All other requests require a fully open connection
    if ($connection['status'] !== MMCP_CID_OPEN)
    {
        http_response_code(400); // Bad Request
        $response = MMCP_DEFAULT_PROTOCOL_ERROR_OBJ();
        $response['id'] = $request_id;
        $response['error']['code'] = -32600;
        $response['error']['message'] = 'Connection not fully initialized. Send notifications/initialized first.';

        $json_response = json_encode($response);
        mmcp_send_error($json_response);
        // can match a request, so log it as a response too
        mmcp_log_access($cid, $request_id, $json_response, false);
        exit();
    }

    // FINALLY! Process the request based on method
    mmcp_process_request($request, $cid);

} // end mmcp_main()




/*****************************************************************************
 ******************************* EXAMPLE CODE ********************************
 *****************************************************************************


// 'registers' the custom endpoint(s) we want to
// handle by returning the actual endpoint, as a
// string (if only one) or in an array; note that
// the handling function must match this
// function's name with the prefix removed
function mmcp_endpoint_registry_health_endpoint()
{
    return ['/health', '/health/']; // include leading slash for consistency
}

// the actual function to handle the custom endpoint
// that we already have a registry function for
function health_endpoint($method)
{
    // this is just an endpoint that Claude sends to in
    // order to check health; if a GET is sent here, just
    // respond with a quick "ok" JSON, otherwise 405 err
    if ($method !== 'GET')
    {
        http_response_code(405);
        $output = [ 'error' => '405 Error: Method not allowed' ];
    }
    else
    {
        http_response_code(200);
        $output = [ 'status' => 'ok' ];
    }

    mmcp_send_response(json_encode($output));
}

// registry function for the tool "add_numbers";
// note that this definition has a title and an
// output schema, which can both be later dropped
// as the protocol version demands
function mmcp_tool_registry_add_numbers()
{
    // neither input nor output schemas require a
    // $schema element detailing the schema spec;
    // it is best NOT to include such in the def.
    $input_schema = [
        'type' => 'object',
        'required' => [ // the 'required' element is optional;
            // an array of strings detailing which params are required
            'a',
            'b'
        ],
        'properties' => [ // the 'properties' element is optional;
            'a' => [
                'type' => 'number',
                'description' => 'First of two numbers to be added together'
            ],
            'b' => [
                'type' => 'number',
                'description' => 'Second of two numbers to be added together'
            ]
        ]
    ];

    // output schemas are optional; if present, tool result should include
    // both normal content, and a "structuredContent" element
    $output_schema = [
        'type' => 'object',
        'required' => [ // 'required' element is optional;
            // an array of strings detailing the output's structure
            'sum'
        ],
        'properties' => [ // 'properties' element is optional;
            'sum' => [
               'type' => 'number',
               'description' => 'Sum of the two numbers'
            ]
        ]
    ];

    $tool_def = [
        'name' => 'add_numbers', // should match the function name
        'description' => 'Adds two numeric values, returning the sum.', // human-readable description of the tool
        'title' => 'Add-Numbers', // optional; intended for UI and end-user contexts
        // 'annotations' => (new stdClass()), // if there were annotation, they'd be here
        'inputSchema' => $input_schema,
        'outputSchema' => $output_schema // optional
    ];

    return $tool_def;
}

// actual tool function for tool "add_numbers";
// requires two params, "a" and "b", which should
// be numeric values, and returns a single result,
// a content of type text that is the numeric sum
// of the two numbers. If either param is missing,
// either param is invalid, or there are extra
// params, instead returns an error result, with
// the content being a text error message. Note
// that since the tool function had an output
// schema, the result has BOTH unstructured
// (normal) content, and a structured content
// which can be dropped as the protocol demands
function add_numbers($params)
{
    // start building the structure of the result
    $result = [
        'isError' => FALSE,
        'content' => []
    ];

    // just in case there's an error
    $error_content = [
        'type' => 'text',
        'text' => ""
    ];

    // validate the params
    // must be two and only two params,
    // "a" and "b", both numerics
    if (count($params) !== 2)
    {
        // error: wrong number of params
        $result['isError'] = TRUE;
        $error_content['text'] = 'Invalid params: must provide exactly 2 parameters';
        $result['content'][] = $error_content;
        return $result;
    }

    if ((!isset($params['a'])) or (!isset($params['b'])))
    {
        // error: param(s) missing
        $result['isError'] = TRUE;
        $error_content['text'] = "Invalid params: parameters must be 'a' and 'b'";
        $result['content'][] = $error_content;
        return $result;
    }

    $a = $params['a'];
    $b = $params['b'];

    if ((!is_numeric($a)) or (!is_numeric($b)))
    {
        // error: invalid params (both must be numeric)
        $result['isError'] = TRUE;
        $error_content['text'] = 'Invalid params: both params must be numeric';
        $result['content'][] = $error_content;
        return $result;
    }

    // process the params
    $sum = $a + $b;

    // create the content of the result
    // (always necessary)
    $content = [
        'type' => 'text',
        'text' => (string) $sum
    ];

    // create the structured content of the result
    // (only necessary if the tool definition
    // included an output schema)
    $structured_content = [ 'sum' => $sum ];

    // return the final result
    $result['content'][] = $content;
    $result['structuredContent'] = $structured_content;

    return $result;
}


 *****************************************************************************
 ***************************** END EXAMPLE CODE ******************************
 *****************************************************************************/


?>
