<?php
/**
 * 
 * 
 */
// Unix time is UTC timezone _always_
class HabariDateTime extends DateTime
{
	private static $default_timezone = 'UTC';
	private static $default_datetime_format = '%c';
	
	
	public function set_default_datetime_format( $format )
	{
		self::$default_datetime_format = $format;
	}
	
	public function get_default_datetime_format()
	{
		return self::$default_datetime_format;
	}
	
	/**
	 * Sets the timezone for Habari and PHP.
	 * 
	 * @param string $timezone A timezone name, not an abbreviation, for example 'America/New York'
	 **/
	public static function set_default_timezone( $timezone )
	{
		date_default_timezone_set( $timezone );
		self::$default_timezone = $timezone;
	}
	
	public static function get_default_timezone()
	{
		return self::$default_timezone;
	}
	
	static function date_create( $time = null, $timezone = null )
	{
		if ( $time instanceOf HabariDateTime ) {
			return $time;
		}
		elseif ( $time instanceOf DateTime ) {
			$time = $time->format('U');
		}
		elseif ( $time == null ) {
			$time = 'now';
		}
		elseif ( is_numeric($time) ) {
			$time = '@' . $time;
		}

		if ( $timezone === null ) {
			$timezone = self::$default_timezone;
		}
		
		// passing the timezone to construct doesn't seem to do anything.
		$datetime = new HabariDateTime($time);
		$datetime->set_timezone($timezone);
		return $datetime;
	}
	
	public function set_date( $year, $month, $day )
	{
		parent::setDate($year, $month, $day);
	}

	public function set_isodate( $year, $week, $day = null )
	{
		parent::setISODate($year, $week, $day);
	}

	public function set_time( $hour, $minute, $second = null )
	{
		parent::setTime($hour, $minute, $second);
	}

	public function set_timezone( $timezone )
	{
		if ( ! $timezone instanceof DateTimeZone ) {
			$timezone = new DateTimeZone($timezone);
		}
		parent::setTimezone($timezone);
	}
	
	/**
	 * Get the timezone name
	 */
	public function get_timezone()
	{
		return parent::getTimezone()->getName();
	}

	public function format($format = null)
	{
		if ( $format === null ) {
			$format = self::$default_datetime_format;
		}
		return parent::format($format);
	}

	public function get($format = null)
	{
		return $this->format($format);
	}

	public function out($format = null)
	{
		echo $this->get($format);
	}

	public function __toString()
	{
		return $this->format('U');
	}

	public function __get($property)
	{
		switch ($property) {
			case 'clone':
				return clone $this;

			case 'sql':
				return $this->format('U');
				break;

			case 'int':
				return intval( $this->format('U') );
				break;

			default:
				$info = getdate($this->format('U'));
				$info['mon0'] = substr('0' . $info['mon'], -2, 2);
				$info['mday0'] = substr('0' . $info['mday'], -2, 2);
				if(isset($info[$property])) {
					return $info[$property];
				}
				return $this->$property;
		}
	}

	public function getdate()
	{
		$info = getdate($this->format('U'));
		$info['mon0'] = substr('0' . $info['mon'], -2, 2);
		$info['mday0'] = substr('0' . $info['mday'], -2, 2);
		return $info;
	}
}

?>
