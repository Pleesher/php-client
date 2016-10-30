<?php
namespace Pleesher\Client\Exception;

class Exception extends \Exception
{
	protected $error_code;

	public function __construct($message = null, $error_code = null, $previous = null)
	{
		parent::__construct($message, null, $previous);
		$this->error_code = $error_code;
	}

	public function getErrorCode()
	{
		return $this->error_code;
	}

	public function __toString()
	{
		ob_start();
		if (!empty($this->error_code))
			echo ' (' . $this->error_code . ')';
		return ob_get_clean();
	}
}
