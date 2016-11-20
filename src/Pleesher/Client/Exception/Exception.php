<?php
namespace Pleesher\Client\Exception;

class Exception extends \Exception
{
	protected $error_code;
	protected $error_parameters;

	public function __construct($message, $error_code = null, array $error_parameters = array(), $previous = null)
	{
		parent::__construct($message, null, $previous);
		$this->error_code = $error_code;
		$this->error_parameters = $error_parameters;
	}

	public function getErrorCode()
	{
		return $this->error_code;
	}

	public function getErrorParameters()
	{
		return $this->error_parameters;
	}

	public function __toString()
	{
		ob_start();
		echo $this->message;
		if (!empty($this->error_code))
			echo ' (' . $this->error_code . ')';
		return ob_get_clean();
	}
}
