<?php

namespace RarWowVendor\Mpdf\Language;

interface ScriptToLanguageInterface
{

	public function getLanguageByScript($script);

	public function getLanguageDelimiters($language);

}
