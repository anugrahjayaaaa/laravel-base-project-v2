# API Error Contract

## HTTP Status Codes

| Code | Meaning | When |
|------|---------|------|
| 200 | OK | Successful read |
| 201 | Created | Resource created |
| 204 | No Content | Resource deleted (no body) |
| 400 | Bad Request | Malformed request, invalid params |
| 401 | Unauthenticated | No/invalid auth credentials |
| 403 | Forbidden | Authenticated but not authorized |
| 404 | Not Found | Resource doesn't exist |
| 409 | Conflict | Business rule violation |
| 422 | Validation Error | Failed form validation |
| 429 | Too Many Requests | Rate limited |
| 500 | Server Error | Internal server error |
| 503 | Service Unavailable | Maintenance/temporary |

## Error Response Format

```json
{
  "message": "Human-readable error message",
  "code": "MACHINE_ERROR_CODE",
  "errors": {
    "field": ["Error detail 1", "Error detail 2"]
  },
  "meta": {
    "request_id": "uuid",
    "timestamp": "2024-01-01T00:00:00Z"
  }
}
```

## Standard Error Codes

| Code | HTTP | Description |
|------|------|-------------|
| VALIDATION_ERROR | 422 | Form validation failed |
| UNAUTHENTICATED | 401 | Authentication required |
| FORBIDDEN | 403 | Insufficient permissions |
| NOT_FOUND | 404 | Resource not found |
| CONFLICT | 409 | Business rule violation |
| RATE_LIMITED | 429 | Too many requests |
| SERVER_ERROR | 500 | Internal server error |
| SERVICE_UNAVAILABLE | 503 | Service temporarily unavailable |

## Security Considerations

Never expose in error responses:
- Stack traces
- File paths
- Database queries
- Environment variables
- Secret keys or credentials
- Internal IP addresses
- Debug information

Log full errors server-side. Return generic messages to clients.