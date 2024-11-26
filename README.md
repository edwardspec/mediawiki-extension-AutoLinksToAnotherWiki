Extension:AutoLinksToAnotherWiki

This extension adds links from this wiki A to another wiki B, assuming:

1) the wiki B has an article named "Some words",
2) an article in the wiki A contains the text "some words" in its text.

Then this text "some words" will wrapped in an external link to the wiki B.

## Example configuration

```php
// This API will be used to determine "what articles exist on another wiki":
$wgAutoLinksToAnotherWikiApiUrl = 'https://example.com/w/api.php';

// Only pages in [[Category:AutoLinks]] will have links added to them.
$wgAutoLinksToAnotherWikiCategoryName = 'AutoLinks'; // Default
