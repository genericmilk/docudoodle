<p align="center">
    <img src="docudoodle.png" width="200" />	
</p>
<h1 align="center">
    Docudoodle v2.3.0
</h1>

This is Docudoodle! 👋 The PHP documentation generator that analyzes your codebase and creates comprehensive documentation using AI. It's perfect for helping you and your team understand your code better through detailed insights into your application's structure and functionality.

Docudoodle is fab if you've taken on an application with no existing documentation allowing your team to get up to speed right away.

Docudoodle writes Markdown files which show up great in Github and other source control providers allowing you to get teams up to speed in a matter of moments.

Better yet, Docudoodle skips already existing documentation files. Allowing a quick top-up run after you have concluded a feature, meaning that the entire process of getting good documentation written is a thing of the past 🚀

## Table of Contents

- [Examples](#examples)
- [Features](#features)
- [Installation](#installation)
- [Usage](#usage)
- [Configuration](#configuration)
  - [OpenAI API Key](#openai-api-key)
  - [Claude API Key](#claude-api-key)
  - [Gemini API Key](#gemini-api-key)
  - [Azure OpenAI Settings](#azure-openai-settings)
  - [Model Selection](#model-selection)
  - [API Provider](#api-provider)
  - [Ollama Configuration](#ollama-configuration)
  - [Token Limits](#token-limits)
  - [File Extensions](#file-extensions)
  - [Skip Directories](#skip-directories)
- [Caching](#caching)
- [Template Variables](#template-variables)
- [Custom Template Example](#custom-template-example)
- [Using Azure OpenAI](#using-azure-openai)
- [Documentation Output Options](#documentation-output-options)
  - [Jira Integration](#jira-integration)
  - [Confluence Integration](#confluence-integration)
  - [Dependencies](#dependencies)
  - [Command Options](#command-options)
- [Running Tests](#running-tests)
- [License](#license)
- [Contributing](#contributing)

## Examples

If you want to see what the output of some documentation looks like, check out the [examples folder](https://github.com/genericmilk/docudoodle/tree/main/examples) in this repo which contains a few examples 🥰

## Features

- **Automatic Documentation Generation**: Effortlessly generates documentation for PHP files by analyzing their content.
- **Smart Caching**: Only processes files that have changed since the last run, saving time and API costs.
- **Orphan Cleanup**: Automatically removes documentation for deleted source files to keep your docs in sync.
- **Flexible AI Integration**: Choose between OpenAI's powerful cloud API, Claude API, Google's Gemini API, or run locally with Ollama models for complete privacy.
- **Ollama Support**: Generate documentation completely offline using your own local Ollama models - perfect for private codebases or when you need to work without an internet connection.
- **Azure OpenAI Support**: Use Microsoft's Azure OpenAI service for documentation generation with enterprise-grade security and compliance.
- **Customizable**: Easily configure source directories, output folders, and other settings to match your workflow.
- **Command-Line Interface**: Includes a simple command-line script for quick documentation generation.

## Installation

Getting started with Docudoodle is super easy! Just use Composer with this command:

```
composer require genericmilk/docudoodle
```

This will set up all the necessary dependencies defined in the `composer.json` file.

## Usage

Ready to create some amazing documentation? Just run this simple command:

```
php artisan docudoodle:generate
```

If you're using OpenAI, make sure to set your API key which looks a bit like this: `sk-XXXXXXXX` in the application configuration file. If you're using Ollama, ensure it's running on your system and properly configured in your settings. For Claude, set your API key in the configuration file.

## Configuration

Docudoodle is highly customizable! The package includes a configuration file at `config/docudoodle.php` that lets you tailor everything to your needs:

### OpenAI API Key

```php
'openai_api_key' => env('OPENAI_API_KEY', ''),
```

Set your OpenAI API key here or in your `.env` file as `OPENAI_API_KEY`. Keys typically start with `sk-XXXXXXXX`.

### Claude API Key

```php
'claude_api_key' => env('CLAUDE_API_KEY', ''),
```

Set your Claude API key here or in your `.env` file as `CLAUDE_API_KEY`.

### Gemini API Key

```php
'gemini_api_key' => env('GEMINI_API_KEY', ''),
```

Set your Gemini API key here or in your `.env` file as `GEMINI_API_KEY`.

### Azure OpenAI Settings

```php
'azure_endpoint' => env('AZURE_OPENAI_ENDPOINT', ''),
'azure_api_key' => env('AZURE_OPENAI_API_KEY', ''),
'azure_deployment' => env('AZURE_OPENAI_DEPLOYMENT', ''),
'azure_api_version' => env('AZURE_OPENAI_API_VERSION', '2023-05-15'),
```

Configure Azure OpenAI integration. You need to provide the endpoint URL, API key, deployment ID, and optionally the API version if using Azure as your API provider.

### Model Selection

```php
'default_model' => env('DOCUDOODLE_MODEL', 'gpt-4o-mini'),
```

Choose which model to use. The default is `gpt-4o-mini` for OpenAI, but you can specify any OpenAI model, Claude model, Gemini model, or Ollama model name in your `.env` file with the `DOCUDOODLE_MODEL` variable.

### API Provider

```php
'default_api_provider' => env('DOCUDOODLE_API_PROVIDER', 'openai'),
```

Choose which API provider to use: 'openai' for cloud-based generation, 'azure' for Azure OpenAI, 'claude' for Claude API, 'gemini' for Gemini API, or 'ollama' for local generation. Set in your `.env` file with `DOCUDOODLE_API_PROVIDER`.

### Ollama Configuration

```php
'ollama_host' => env('OLLAMA_HOST', 'localhost'),
'ollama_port' => env('OLLAMA_PORT', '11434'),
```

Configure your Ollama host and port if using Ollama as the API provider. The defaults work with standard Ollama installations.

### Token Limits

```php
'max_tokens' => env('DOCUDOODLE_MAX_TOKENS', 10000),
```

Control the maximum number of tokens for API calls. Adjust this in your `.env` file with `DOCUDOODLE_MAX_TOKENS` if needed.

### File Extensions

```php
'default_extensions' => ['php', 'yaml', 'yml'],
```

Specify which file types Docudoodle should process. By default, it handles PHP and YAML files.

### Skip Directories

```php
'default_skip_dirs' => ['vendor/', 'node_modules/', 'tests/', 'cache/', '/wildcard/*/path/'],
```

Define directories that should be excluded from documentation generation.

You can publish the configuration file to your project using:

```
php artisan vendor:publish --tag=docudoodle-config
```

## Caching

To improve performance and reduce API calls on subsequent runs, Docudoodle implements a caching mechanism.

**How it works:**

1.  When a source file is processed, a hash of its content is calculated.
2.  This hash is stored in a cache file (`.docudoodle_cache.json` by default) alongside a hash representing the relevant parts of the configuration (model, prompt template, API provider).
3.  On the next run:
    - The overall configuration hash is checked. If it differs, the cache is invalidated, and all files are reprocessed.
    - If the configuration hash matches, the content hash of each source file is compared to the stored hash.
    - Files with matching hashes are skipped.
    - Files with different hashes or files not found in the cache are processed, and the cache is updated.
4.  **Orphan Cleanup:** If a source file is deleted, Docudoodle detects this and removes its corresponding documentation file from the output directory and its entry from the cache.

**Configuration:**

You can control caching via `config/docudoodle.php`:

- `use_cache` (boolean, default: `true`): Set to `false` to disable the caching mechanism entirely.
- `cache_file_path` (string|null, default: `null`): Specifies the absolute path to the cache file. If `null` or empty, it defaults to `.docudoodle_cache.json` inside the configured `output_dir`.
- `force_rebuild` (boolean, default: `false`): When set to `true`, regenerates all documentation regardless of the cache status. Useful for manual cache invalidation.

**Command Line Options:**

- `--no-cache`: Disables caching for this run, forcing reprocessing of all files. This overrides the `use_cache` config setting.
- `--force-rebuild`: Forces regeneration of all documentation files regardless of cache status. This overrides the `force_rebuild` config setting.
- `--cache-path="/path/to/your/cache.json"`: Specifies a custom absolute path for the cache file for this run, overriding the `cache_file_path` config setting.

**Cache File Format:**

The cache file is a JSON document with the following structure:

```json
{
	"_config_hash": "abc123...", // Hash of current configuration settings
	"/path/to/file1.php": "def456...", // File path and content hash pairs
	"/path/to/file2.php": "789ghi..."
}
```

When the configuration changes (different model, API provider, or prompt template), the `_config_hash` value changes, which triggers a full rebuild on the next run.

## Template Variables

When creating custom prompt templates for documentation generation, you can use the following variables:

| Variable         | Description                                    |
| ---------------- | ---------------------------------------------- |
| `{FILE_PATH}`    | The full path to the file being documented     |
| `{FILE_CONTENT}` | The content of the file being processed        |
| `{FILE_NAME}`    | The filename with extension (e.g., `User.php`) |
| `{EXTENSION}`    | The file extension (e.g., `php`)               |
| `{BASE_NAME}`    | The filename without extension (e.g., `User`)  |
| `{DIRECTORY}`    | The directory containing the file              |

## Custom Template Example

Create a markdown file for your custom prompt template:

````markdown
# My Custom Documentation Template

Please document this {EXTENSION} file: {FILE_PATH}

Here's the code:

```{EXTENSION}
{FILE_CONTENT}
```
````

Then specify the custom template path in your `.env` file:

```
DOCUDOODLE_PROMPT_TEMPLATE=./path/to/template.md
```

## Using Azure OpenAI

To generate documentation using Azure OpenAI:

1. Set up your Azure configuration in the `.env` file:

```
AZURE_OPENAI_ENDPOINT=https://your-resource.openai.azure.com
AZURE_OPENAI_API_KEY=your-api-key
AZURE_OPENAI_DEPLOYMENT=your-deployment-id
DOCUDOODLE_API_PROVIDER=azure
```

2. Run the command:

```
php artisan docudoodle:generate
```

You can also specify Azure parameters directly in the command:

```
php artisan docudoodle:generate --api-provider=azure --azure-endpoint=https://your-resource.openai.azure.com --azure-deployment=your-deployment
```

## Documentation Output Options

By default, Docudoodle generates documentation in Markdown format in your specified output directory.

### Jira Integration

Docudoodle can publish documentation directly to Jira as issues. To enable this:

1. Configure your Jira settings in `.env`:

```
DOCUDOODLE_JIRA_ENABLED=true
JIRA_HOST=https://your-domain.atlassian.net
JIRA_API_TOKEN=your-api-token
JIRA_EMAIL=your-email@example.com
JIRA_PROJECT_KEY=your-project-key
JIRA_ISSUE_TYPE=Documentation
```

2. Run the command with Jira enabled:

```
php artisan docudoodle:generate --jira
```

### Confluence Integration

Docudoodle can publish documentation directly to Confluence. To enable this:

1. Configure your Confluence settings in `.env`:

```
DOCUDOODLE_CONFLUENCE_ENABLED=true
CONFLUENCE_HOST=https://your-domain.atlassian.net
CONFLUENCE_API_TOKEN=your-api-token
CONFLUENCE_EMAIL=your-email@example.com
CONFLUENCE_SPACE_KEY=your-space-key
CONFLUENCE_PARENT_PAGE_ID=optional-parent-page-id
```

2. Run the command with Confluence enabled:

```
php artisan docudoodle:generate --confluence
```

### Dependencies

To use Jira or Confluence integration, make sure to install the Guzzle HTTP client:

```
composer require guzzlehttp/guzzle:^7.0
```

### Command Options

```
php artisan docudoodle:generate
    --jira              # Enable Jira documentation output
    --confluence        # Enable Confluence documentation output
    --no-files         # Disable file system documentation output
```

You can combine these options as needed. For example, to generate documentation in both Jira and Confluence but not in the file system:

```
php artisan docudoodle:generate --jira --confluence --no-files
```

## Running Tests

Want to make sure everything's working perfectly? Run the tests with:

```
./vendor/bin/phpunit
```

Or if you're using Laravel's test runner:

```
php artisan test
```

## License

This project is licensed under the MIT License. Check out the LICENSE file for all the details.

## Contributing

We'd love your help making Docudoodle even better! Feel free to submit a pull request or open an issue for any enhancements or bug fixes. Everyone's welcome! 🎉
