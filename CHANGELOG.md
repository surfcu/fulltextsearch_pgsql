# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2025-02-02

### Added
- Initial release
- PostgreSQL full-text search implementation using ts_vector
- GIN indexes for fast search performance
- Trigram similarity search using pg_trgm extension
- **Multi-language support with 28 languages including Turkish (Türkçe)**
- Language validation with supported languages list
- Relevance ranking with ts_rank
- Tag and metadata filtering
- Access control integration
- Support for Nextcloud 25-30
- Comprehensive documentation including Turkish language guide

### Features
- Native PostgreSQL full-text search
- No additional infrastructure required
- Lower resource footprint compared to Elasticsearch
- Fuzzy matching with trigrams
- **28 supported languages: Arabic, Armenian, Basque, Catalan, Danish, Dutch, English, Finnish, French, German, Greek, Hindi, Hungarian, Indonesian, Irish, Italian, Lithuanian, Nepali, Norwegian, Portuguese, Romanian, Russian, Serbian, Spanish, Swedish, Tamil, Turkish, Yiddish**
- Language validation and error handling
- Search result ranking
- Document indexing and updating
- Provider-based filtering
- User access control

### Technical Details
- Uses PostgreSQL's native full-text search capabilities
- Implements IFullTextSearchPlatform interface
- Automatic index table creation
- GIN index optimization
- Prepared statement security
- PSR-4 autoloading
- Nextcloud coding standards compliance
