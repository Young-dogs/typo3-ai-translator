# API Contract

The extension communicates with your translation service over HTTP POST.

## Single text

```json
// Request
{ "source_language": "EN", "destination_language": "NL", "texts": ["Hello world"] }

// Response
{ "translations": ["Hallo wereld"] }
```

## Batch (multiple fields, one request per record)

```json
// Request
{
  "source_language": "EN",
  "destination_language": "NL",
  "texts": {
    "header": "Welcome to Young Dogs",
    "bodytext": "We help businesses grow..."
  }
}

// Response
{
  "translations": {
    "header": "Welkom bij Young Dogs",
    "bodytext": "We helpen bedrijven groeien..."
  }
}
```

## Headers

Sent with every request:

```
Content-Type: application/json
Accept: application/json
X-API-Key: <your api key>
```
