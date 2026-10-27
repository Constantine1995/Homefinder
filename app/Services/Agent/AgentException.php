<?php

namespace App\Services\Agent;

use RuntimeException;

/**
 * The search and agent service (agent/) cannot be reached, or fails: the product keeps working without it.
 */
class AgentException extends RuntimeException {}
