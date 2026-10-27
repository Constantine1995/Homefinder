<?php

namespace App\Services\Knowledge;

use RuntimeException;

/**
 * A knowledge base document that cannot be imported: unreadable, or about a complex the developer does not have.
 */
class KnowledgeException extends RuntimeException {}
