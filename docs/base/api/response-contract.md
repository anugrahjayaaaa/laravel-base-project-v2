# API Response Contract

## Envelope Structure

All successful responses use a consistent envelope:

```json
{
  "data": { ... },
  "meta": {
    "request_id": "uuid",
    "timestamp": "2024-01-01T00:00:00Z"
  }
}
```

## Single Resource

```json
{
  "data": {
    "id": 1,
    "name": "Example",
    "attributes": { ... }
  },
  "meta": { ... }
}
```

## Collection

```json
{
  "data": [
    { "id": 1, "name": "Example" },
    { "id": 2, "name": "Example 2" }
  ],
  "meta": {
    "pagination": {
      "current_page": 1,
      "from": 1,
      "last_page": 10,
      "per_page": 15,
      "to": 15,
      "total": 150
    }
  }
}
```

## Errors

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": ["The email field is required."]
  },
  "code": "VALIDATION_ERROR"
}
```

## Pagination

Use standard Laravel pagination:
- `page` query parameter
- `per_page` parameter (default 15, max 100)
- Links header: `Link: <...?page=2>; rel="next"`
- Meta includes: current_page, from, last_page, per_page, to, total

## Filtering

Standard query parameters:
- `?filter[field]=value` — exact match
- `?filter[status]=active,inactive` — in array
- `?filter[created_after]=2024-01-01` — date range

## Sorting

Standard query parameters:
- `?sort=field` — ascending
- `?sort=-field` — descending
- `?sort=field1,-field2` — multi-column

## Meta

Always include:
- `request_id` — correlation ID for tracing
- `timestamp` — response time in ISO 8601