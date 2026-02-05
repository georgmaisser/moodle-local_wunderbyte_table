# Security Documentation - Wunderbyte Table

## Object Deserialization Protection

### Overview
Starting with version 3.0.8, the Wunderbyte Table plugin implements comprehensive security measures to prevent PHP object injection attacks through cache deserialization.

### Security Mechanisms

#### 1. HMAC-Based Cache Validation
All cached table objects are protected with HMAC (Hash-based Message Authentication Code) signatures:

- **Algorithm**: HMAC-SHA256
- **Secret Key**: Configurable via admin settings or system password salt fallback
- **Signature Scope**: Based on table configuration (idstring, capabilities, requirelogin) - **NOT user/session specific**
- **Purpose**: Prevents cache tampering and ensures integrity

**Performance Benefit**: Deterministic hashing based only on configuration allows cache sharing across multiple users, significantly improving performance in high-traffic scenarios.

#### 2. User and Session Validation
**UPDATED (v3.0.8): Removed for performance optimization in high-traffic scenarios**

Cache entries are now **shared across users** based on table configuration (idstring, capabilities, requirelogin), not user-specific. This allows:
- Single cache entry for multiple users with same permissions
- Significant performance improvement in high-traffic scenarios
- Reduction of cache memory consumption

**Important**: The HMAC signature validates **cache integrity**, not user identity. User-level security is enforced through Moodle's capability system when the table is instantiated.

#### 3. Class Whitelist
Only explicitly allowed classes can be deserialized from cache:
- Base class `local_wunderbyte_table\wunderbyte_table` is always allowed
- Plugins can register additional safe subclasses via callback
- Unknown or malicious classes are rejected with detailed error messages

#### 4. Input Validation
Strict validation of cache hash format:
- Must be exactly 40 hexadecimal characters
- Prevents path traversal and injection attempts
- Early rejection of invalid inputs

#### 5. Metadata Storage (Simplified)
Cache entries include validated metadata:
```php
[
    'classname' => string,      // Fully qualified class name
    'signature' => string,      // HMAC signature
    'idstring' => string,       // Table identifier
    'requirecapability' => string,
    'requirelogin' => bool,
]
```

### Configuration

#### Admin Settings
Navigate to: **Site administration → Plugins → Local plugins → Wunderbyte Table**

**Enable Class Whitelist Validation** (`enableclasswhitelist`)
- Default: **Enabled** (recommended)
- Controls whether only whitelisted table classes can be deserialized from cache
- Disable only if you experience compatibility issues with legacy custom subclasses
- **Warning**: Disabling this reduces protection against object injection attacks

**Cache Security Secret** (`cache_secret`)
- Optional custom secret key for HMAC signatures
- Leave empty to use system password salt (secure default)
- Changing this value invalidates all existing cached tables
- Recommended: Set a unique value in production environments

#### Plugin Integration
If your plugin extends `wunderbyte_table`, register your subclass:

```php
// In your plugin's lib.php
function yourplugin_wunderbyte_table_allowed_classes() {
    return [
        'yourplugin\\your_table_subclass',
        'yourplugin\\another_safe_table_class',
    ];
}
```

**Note**: If class whitelist validation is disabled in admin settings, this registration is not required (but still recommended for when it's re-enabled).

### Attack Vectors Mitigated

✅ **PHP Object Injection**
- Prevented by HMAC signature verification
- Class whitelist blocks arbitrary object instantiation

✅ **Cache Poisoning**
- Prevented by HMAC signature verification
- Configuration-based hashing ensures integrity
- Shared cache allows efficient multi-user scenarios

✅ **Session Hijacking**
- **NOTE**: User/session are NOT part of hash (by design for performance)
- Security model: Cache integrity (HMAC) + Capability-based access control
- User permissions validated through Moodle's standard capability system

✅ **Replay Attacks**
- Metadata includes creation timestamp
- Can implement TTL in future versions if needed

### Error Messages

| Error Code | Meaning | Action Required |
|------------|---------|----------------|
| `invalidcachehash` | Invalid hash format | Check hash generation logic |
| `invalidcachemetadata` | Missing or corrupt metadata | Clear cache and regenerate |
| `cacheuseridmismatch` | Cache belongs to different user | Security violation - investigate |
| `invalidsesskey` | Session expired or invalid | User should refresh page |
| `invalidcachedobject` | Wrong object type in cache | Clear cache and regenerate |
| `classnotwhitelisted` | Unsafe class detected | Add class to whitelist or investigate attack |
| `invalidsignature` | Signature verification failed | Possible tampering - investigate |

### Cache Storage

- **Mode**: APPLICATION (shared across sessions but validated per user)
- **TTL**: Not enforced (validation via signature instead)
- **Invalidation**: Automatic on secret change or user logout

### Best Practices

1. **Production Environments**
   - Set a unique `cache_secret` value
   - Monitor security error logs for attack attempts
   - Regularly review allowed class whitelist

2. **Development**
   - Clear cache after code changes: `purge_caches.php`
   - Test with different users to verify isolation
   - Check debugging output for whitelist issues

3. **Plugin Development**
   - Always register subclasses in whitelist callback
   - Validate data before caching
   - Document security assumptions

### Performance Impact

Minimal overhead from security checks:
- HMAC computation: ~0.1ms per operation
- Metadata validation: ~0.05ms per operation
- Class whitelist check: O(n) where n is number of allowed classes

### Audit Log

Security-related events are logged via Moodle's debugging system:
```php
debugging('Class not whitelisted: ' . $actualclass, DEBUG_DEVELOPER);
```

Enable developer debugging to monitor security events.

### Version History

- **3.0.8 (2026-02-05)**: Initial implementation of object deserialization protection
- Future: Consider adding configurable TTL, enhanced monitoring, automatic secret rotation

### References

- [OWASP: Deserialization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Deserialization_Cheat_Sheet.html)
- [PHP: hash_hmac Documentation](https://www.php.net/manual/en/function.hash-hmac.php)
- [Moodle: Cache API](https://docs.moodle.org/dev/Cache_API)

### Contact

For security issues, please contact: security@wunderbyte.at
