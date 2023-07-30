# RemoteWiki

The extension provides `{{#remote_version:}}` parser function capable to fetch
remote MediaWiki version and installed extensions versions and URLs data. Supports authentication
via [BotPasswords](https://www.mediawiki.org/wiki/Manual:Bot_passwords).

# Requirements

* MediaWiki 1.35+

# Setup

* Clone the repository into `./extensions/RemoteWiki`
* Add `extensions/RemoteWiki/composer.json` to your `composer.local.json`
* Run `composer update --no-dev`
* Add `wfLoadExtension('RemoteWiki');` to the bottom of `LocalSettings.php`

# Configuration

* `$wgRemoteWikiCacheTTL` - time in seconds for cache TTL
* `$wgRemoteWikiBotPasswords` - store BotPasswords for accessing private wikis

# Usage

Fetch remote wiki version

```
{{#remote_version:https://wiki.com/w/api.php}}
```

Fetch remote wiki extensions versions

```
{{#remote_version:https://wiki.com/w/api.php|extensions}}
```

Fetch remote wiki extensions URLs

```
{{#remote_version:https://wiki.com/w/api.php|extension-urls}}
```

For private wiki add bot credentials into `$wgRemoteWikiBotPasswords` setting like below:

```php
$wgRemoteWikiBotPasswords['mywiki.com/w/api.php'] = [
	'username' => 'testbot@user',
	'password' => 'testpassword'
];
```

# Functions output and parsing examples

`remote_version` function not supplied with 2nd argument will simply output raw mediawiki version number, eg:

```
{{#remote_version:https://wiki.com/w/api.php}} = 1.35.5
```

`remote_version` with 2nd argument set to `extensions` will return list of
extensions and their versions (if any) or git commits (if version information
is missing) or question marks if version can't be detected, using `:` as an
separator between extension and version and `,` as a record separator, eg:

```
{{#remote_version:https://wiki.com/w/api.php|extensions}} = ParserFunctions:1.0,SemanticWatchlist:2.0,PageForms:5.0
```

this output can be easily parsed via `arraymap` and `explode` parser functions, eg:

```
Displays table of extensions and their versions

{|class="wikitable"
! Extension
! Version
|-
{{#arraymap:{{#remote_version:https://wiki.com/w/api.php|extensions}}
|,
|@
|
{{!}}-
{{!}} '''{{#explode:@|:|0}}'''
{{!}} <code>{{#explode:@|:|1}}</code>
{{!}}-
| 
}}
|-
|}
```

`remote_version` with 2nd argument set to `extension-urls` will return list of
extensions and their URLs (if any) or question marks if the URL is not set,
using `:` as a separator between extension and URL and `|` as a record
separator (rather than `,` since commas are valid in URLs). A formatted output
can be achieved with:

```
Displays table of extensions and their URLs

{|class="wikitable"
! Extension
! URL
|-
{{#arraymap:{{#remote_version:https://wiki.com/w/api.php|extension-urls}}
|{{!}}
|@
|
{{!}}-
{{!}} '''{{#explode:@|:|0}}'''
{{!}} <code>{{#explode:@|:|1|2}}</code>
{{!}}-
| 
}}
|-
|}
```