# RemoteWiki

The extension provides `{{#remote_version:}}` parser function capable to fetch
remote MediaWiki version and installed extensions versions data. Supports authentication
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

For private wiki add bot credentials into `$wgRemoteWikiBotPasswords` setting like below:

```php
$wgRemoteWikiBotPasswords['mywiki.com/w/api.php'] = [
	'username' => 'testbot@user',
	'password' => 'testpassword'
];
```
