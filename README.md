# mmcp
Mini-MCP Server: A minimalist MCP server written in procedural PHP and stripped down to just what we need.

**NEWEST:** v. 1.1.0

Changes:
 - Added ability to operate via STDIO
 - Long-running tools can communicate this to the client (somewhat)

To-do:
 - Allow server tools to send progress notifications in STDIO

**ORIGINAL:** v. 1.0.0

Limitations:
 - Only later protocols (2025-03-26 and 2025-06-18)
 - No SSE endpoint, no continuous communication channel
 - No OAuth or other built-in authorization
 - Only HTTP, no STDIO transport method
 - Only tools; no resources, prompts, etc.
 - Follows protocol rigidly (custom endpoints allow slight violations)
 - No pagination of data

Usage: For now, refer to the header comments of the main file (mini-mcp-server.php) for usage
