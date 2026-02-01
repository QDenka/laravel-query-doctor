# Security

How the package handles sensitive data and access control.

## Principle

Query Doctor captures SQL queries, which can contain sensitive data in their bindings (passwords, tokens, emails, personal information). The package is designed to never persist raw PII.

## PII Masking

### What Gets Masked

1. **Column-based masking**: If a WHERE clause references a column listed in `query-doctor.masking.columns`, the corresponding binding value is replaced with `[MASKED]` before storage.

   Default masked columns: `password`, `secret`, `token`, `api_key`, `access_token`, `refresh_token`, `credit_card`, `ssn`, `social_security`.

2. **Pattern-based masking**: String bindings matching regex patterns in `query-doctor.masking.value_patterns` are masked regardless of column name.

   Default patterns detect: email addresses, phone numbers, SSN-format strings.

3. **Hash-only storage**: Instead of storing raw bindings, the package stores a SHA-256 hash of the serialized bindings array (`bindings_hash`). This allows duplicate detection (same query + same bindings = exact duplicate) without keeping the actual values.

### What's NOT Masked

- The SQL statement itself (with `?` placeholders) is stored as-is. It doesn't contain binding values.
- Query execution time, connection name, route, and stack traces are stored.
- Table and column names in the SQL are stored (these are schema, not data).

### Custom Masking Rules

Add your own columns and patterns in config:

```php
'masking' => [
    'columns' => [
        'password', 'secret', 'token', // defaults
        'date_of_birth',               // your addition
        'national_id',                 // your addition
    ],
    'value_patterns' => [
        '/^[A-Z]{2}\d{6}$/',           // passport number format
    ],
],
```

### Disabling Masking

Not recommended, but if your dev environment has no real PII:

```php
'masking' => [
    'enabled' => false,
],
```

## Dashboard Access Control

### Environment Gate

The dashboard is only accessible when `app.env` is in the `query-doctor.allowed_environments` list. Default: `local` and `staging`.

In production, the dashboard routes aren't registered at all (not just blocked — they don't exist).

### Custom Auth Gate

Add middleware for authentication and authorization:

```php
'dashboard' => [
    'middleware' => ['auth', 'can:view-query-doctor'],
],
```

Then define the gate in your `AuthServiceProvider`:

```php
Gate::define('view-query-doctor', function ($user) {
    return $user->isAdmin();
});
```

### Route Protection

The `QueryDoctorMiddleware` performs these checks on every dashboard request:

1. Is the current environment in `allowed_environments`? If not → 403.
2. Are additional middleware configured? Apply them.

## Storage Security

### File Permissions

The SQLite file is created with standard Laravel storage permissions. If your `storage/` directory is properly secured (not publicly accessible), the SQLite file is too.

### No Network Exposure

The SQLite database is local. It's not accessible over the network. The only way to read it is through the dashboard (which is access-controlled) or the CLI commands (which require shell access).

### Retention

Old data is automatically deleted based on `query-doctor.storage.retention_days` (default 14 days). This limits the window of exposure if the file is compromised.

## What the Package Never Does

- Never sends data to external servers.
- Never logs raw binding values to Laravel's log files.
- Never exposes data in production (unless you explicitly add `production` to `allowed_environments`).
- Never modifies your database schema or data.
- Never stores credentials that appear in query bindings.
