<?php
namespace Froxlor\Frontend\Modules;

/**
 * This file is part of the Froxlor project.
 * Copyright (c) 2003-2009 the SysCP Team (see authors).
 * Copyright (c) 2010 the Froxlor Team (see authors).
 *
 * For the full copyright and license information, please view the COPYING
 * file that was distributed with this source code. You can also view the
 * COPYING file online at http://files.froxlor.org/misc/COPYING.txt
 *
 * @copyright (c) the authors
 * @author Florian Lippert <flo@syscp.org> (2003-2009)
 * @author Froxlor team <team@froxlor.org> (2010-)
 * @license GPLv2 http://files.froxlor.org/misc/COPYING.txt
 * @package Panel
 *         
 */
use Froxlor\Database\Database;
use Froxlor\Settings;
use Froxlor\Api\Commands\SubDomains as SubDomains;
use Froxlor\Api\Commands\Certificates as Certificates;
use Froxlor\Frontend\FeModule;

class CustomerDomains extends FeModule
{

	public function overview()
	{
		// redirect if this customer page is hidden via settings
		if (Settings::IsInList('panel.customer_hide_options', 'domains')) {
			\Froxlor\UI\Response::redirectTo('index.php?module=CustomerIndex');
		}

		try {
			$json_result = SubDomains::getLocal(\Froxlor\CurrentUser::getData())->listing();
		} catch (\Exception $e) {
			\Froxlor\UI\Response::dynamic_error($e->getMessage());
		}
		$result = json_decode($json_result, true)['data'];

		$alldomains = $result['list'];
		$domains = array();
		$parentdomains_count = 0;
		$result = array(
			'list' => array(),
			'count' => 0
		);
		foreach ($alldomains as $domain) {
			if ($domain['parentdomainid'] == '0') {
				$this->formatDomain($domain, $parentdomains_count);
				// add to array by parentdomain
				$domains[$domain['domain']] = $domain;
				$domains[$domain['domain']]['subdomains'] = array();
				$result['count'] ++;
				// now iterate through domains and get all subdomains of this domain
				foreach ($alldomains as $subdomain) {
					if ($subdomain['parentdomainid'] == $domain['id']) {
						$this->formatDomain($subdomain, $parentdomains_count);
						$domains[$domain['domain']]['subdomains'][] = $subdomain;
						$result['count'] ++;
					}
				}
				\Froxlor\PhpHelper::sortListBy($domains[$domain['domain']]['subdomains'], 'domain');
			}
		}
		$result['list'] = $domains;

		\Froxlor\PhpHelper::sortListBy($result['list'], 'domain');

		if (Settings::Get('system.awstats_enabled') == '1') {
			$statsapp = 'awstats';
		} else {
			$statsapp = 'webalizer';
		}

		// domain add form
		$domain_add_form = "";
		if (\Froxlor\CurrentUser::getField('subdomains') != 0) {
			$domain_add_form = $this->domainForm();
		}

		\Froxlor\Frontend\UI::TwigBuffer('customer/domains/index.html.twig', array(
			'page_title' => $this->lng['panel']['domains'],
			'domains' => $result,
			'parentdomains_count' => $parentdomains_count,
			'statsapp' => $statsapp,
			'form_data' => $domain_add_form
		));
	}

	private function formatDomain(&$domain, &$parentdomains_count)
	{
		$idna = new \Froxlor\Idna\IdnaWrapper();
		// idna convert
		$domain['domain'] = $idna->decode($domain['domain']);
		$domain['aliasdomain'] = $idna->decode($domain['aliasdomain']);
		// increase parentdomain counter
		if ($domain['parentdomainid'] == '0' && $domain['caneditdomain'] == '1') {
			$parentdomains_count ++;
		}
		// get ssl-ips if activated
		$domain['show_ssledit'] = false;
		if (Settings::Get('system.use_ssl') == '1' && \Froxlor\Domain\Domain::domainHasSslIpPort($domain['id']) && $domain['caneditdomain'] == '1' && $domain['letsencrypt'] == 0) {
			$domain['show_ssledit'] = true;
		}
		// check for set ssl-certs to show different state-icons
		// nothing (ssl_global)
		$domain['domain_hascert'] = 0;
		$ssl_stmt = Database::prepare("SELECT * FROM `" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "` WHERE `domainid` = :domainid");
		$ssl_result = Database::pexecute_first($ssl_stmt, array(
			"domainid" => $domain['id']
		));
		if (is_array($ssl_result) && isset($ssl_result['ssl_cert_file']) && $ssl_result['ssl_cert_file'] != '') {
			// own certificate (ssl_customer_green)
			$domain['domain_hascert'] = 1;
		} else {
			// check if it's parent has one set (shared)
			if ($domain['parentdomainid'] != 0) {
				$ssl_result = Database::pexecute_first($ssl_stmt, array(
					"domainid" => $ssl_result['parentdomainid']
				));
				if (is_array($ssl_result) && isset($ssl_result['ssl_cert_file']) && $ssl_result['ssl_cert_file'] != '') {
					// parent has a certificate (ssl_shared)
					$ssl_result['domain_hascert'] = 2;
				}
			}
		}
		// show correct documentroot
		if (strpos($domain['documentroot'], \Froxlor\CurrentUser::getField('documentroot')) === 0) {
			$domain['documentroot'] = \Froxlor\FileDir::makeCorrectDir(str_replace(\Froxlor\CurrentUser::getField('documentroot'), "/", $domain['documentroot']));
		}
		// termination date formatting
		$domain['termination_date'] = str_replace("0000-00-00", "", $domain['termination_date']);
		if ($domain['termination_date'] != "") {
			$cdate = strtotime($domain['termination_date'] . " 23:59:59");
			$today = time();
			if ($cdate < $today) {
				$domain['termination_info'] = 'ban';
			} else {
				$domain['termination_info'] = 'exclamation-circle';
			}
		}
	}

	private function domainForm($result = array())
	{
		$stmt = Database::prepare("
			SELECT `id`, `domain`, `documentroot`, `ssl_redirect`,`isemaildomain`,`letsencrypt` FROM `" . TABLE_PANEL_DOMAINS . "`
			WHERE `customerid` = :customerid
			AND `parentdomainid` = '0'
			AND `email_only` = '0'
			AND `caneditdomain` = '1'
			ORDER BY `domain` ASC
		");
		Database::pexecute($stmt, array(
			"customerid" => \Froxlor\CurrentUser::getField('customerid')
		));
		$domains = '';
		$idna_convert = new \Froxlor\Idna\IdnaWrapper();
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$domains .= \Froxlor\UI\HTML::makeoption($idna_convert->decode($row['domain']), $row['domain']);
		}

		$sel_value = ! empty($result) && isset($result['aliasdomain']) ? $result['aliasdomain'] : null;
		$aliasdomains = \Froxlor\UI\HTML::makeoption($this->lng['domains']['noaliasdomain'], 0, $sel_value, true);
		$domains_stmt = Database::prepare("
			SELECT `d`.`id`, `d`.`domain` FROM `" . TABLE_PANEL_DOMAINS . "` `d`, `" . TABLE_PANEL_CUSTOMERS . "` `c`
			WHERE `d`.`aliasdomain` IS NULL
			AND `d`.`id` <> `c`.`standardsubdomain`
			AND `d`.`parentdomainid` = '0'
			AND `d`.`customerid`=`c`.`customerid`
			AND `d`.`email_only`='0'
			AND `d`.`customerid`= :customerid
			ORDER BY `d`.`domain` ASC
		");
		Database::pexecute($domains_stmt, array(
			"customerid" => \Froxlor\CurrentUser::getField('customerid')
		));

		while ($row_domain = $domains_stmt->fetch(\PDO::FETCH_ASSOC)) {
			$aliasdomains .= \Froxlor\UI\HTML::makeoption($idna_convert->decode($row_domain['domain']), $row_domain['id'], $sel_value);
		}

		$redirectcode = '';
		if (Settings::Get('customredirect.enabled') == '1') {
			$sel_value = ! empty($result) ? \Froxlor\Domain\Domain::getDomainRedirectId($result['id']) : null;
			$codes = \Froxlor\Domain\Domain::getRedirectCodesArray();
			foreach ($codes as $rc) {
				$redirectcode .= \Froxlor\UI\HTML::makeoption($rc['code'] . ' (' . $this->lng['redirect_desc'][$rc['desc']] . ')', $rc['id'], $sel_value);
			}
		}

		// check if we at least have one ssl-ip/port, #1179
		$ssl_ipsandports = '';
		$ssl_ip_stmt = Database::prepare("
			SELECT COUNT(*) as countSSL
			FROM `" . TABLE_PANEL_IPSANDPORTS . "` pip
			LEFT JOIN `" . TABLE_DOMAINTOIP . "` dti ON dti.id_ipandports = pip.id
			WHERE pip.`ssl`='1'
		");
		Database::pexecute($ssl_ip_stmt);
		$resultX = $ssl_ip_stmt->fetch(\PDO::FETCH_ASSOC);
		if (isset($resultX['countSSL']) && (int) $resultX['countSSL'] > 0) {
			$ssl_ipsandports = 'notempty';
		}

		$sel_value = ! empty($result) && isset($result['openbasedir_path']) ? $result['openbasedir_path'] : null;
		$openbasedir = \Froxlor\UI\HTML::makeoption($this->lng['domain']['docroot'], 0, $sel_value, true) . \Froxlor\UI\HTML::makeoption($this->lng['domain']['homedir'], 1, $sel_value, true);
		
		if (! empty($result)) {
			// create serveralias options
			$serveraliasoptions = "";
			$_value = '2';
			if ($result['iswildcarddomain'] == '1') {
				$_value = '0';
			} elseif ($result['wwwserveralias'] == '1') {
				$_value = '1';
			}
			$serveraliasoptions .= \Froxlor\UI\HTML::makeoption($this->lng['domains']['serveraliasoption_wildcard'], '0', $_value, true, true);
			$serveraliasoptions .= \Froxlor\UI\HTML::makeoption($this->lng['domains']['serveraliasoption_www'], '1', $_value, true, true);
			$serveraliasoptions .= \Froxlor\UI\HTML::makeoption($this->lng['domains']['serveraliasoption_none'], '2', $_value, true, true);
			
			if (preg_match('/^https?\:\/\//', $result['documentroot']) && \Froxlor\Validate\Form\Data::validateUrl($result['documentroot'])) {
				if (Settings::Get('panel.pathedit') == 'Dropdown') {
					$urlvalue = $result['documentroot'];
					$pathSelect = \Froxlor\FileDir::makePathfield(\Froxlor\CurrentUser::getField('documentroot'), \Froxlor\CurrentUser::getField('guid'), \Froxlor\CurrentUser::getField('guid'));
				} else {
					$urlvalue = '';
					$pathSelect = \Froxlor\FileDir::makePathfield(\Froxlor\CurrentUser::getField('documentroot'), \Froxlor\CurrentUser::getField('guid'), \Froxlor\CurrentUser::getField('guid'), $result['documentroot'], true);
				}
			} else {
				$urlvalue = '';
				$pathSelect = \Froxlor\FileDir::makePathfield(\Froxlor\CurrentUser::getField('documentroot'), \Froxlor\CurrentUser::getField('guid'), \Froxlor\CurrentUser::getField('guid'), $result['documentroot']);
			}
			
			// Fudge the result for ssl_redirect to hide the Let's Encrypt steps
			$result['temporary_ssl_redirect'] = $result['ssl_redirect'];
			$result['ssl_redirect'] = ($result['ssl_redirect'] == 0 ? 0 : 1);
		} else {
			$pathSelect = \Froxlor\FileDir::makePathfield(\Froxlor\CurrentUser::getField('documentroot'), \Froxlor\CurrentUser::getField('guid'), \Froxlor\CurrentUser::getField('guid'));
		}

		$phpconfigs = '';
		$has_phpconfigs = false;
		if (\Froxlor\CurrentUser::getField('allowed_phpconfigs') != "") {
			$sel_value = ! empty($result) && isset($result['phpsettingid']) ? $result['phpsettingid'] : ((int) Settings::Get('phpfpm.enabled') == 1 ? Settings::Get('phpfpm.defaultini') : Settings::Get('system.mod_fcgid_defaultini'));
			$has_phpconfigs = true;
			$allowed_cfg = json_decode(\Froxlor\CurrentUser::getField('allowed_phpconfigs'), JSON_OBJECT_AS_ARRAY);
			$phpconfigs_result_stmt = Database::query("
				SELECT c.*, fc.description as interpreter
				FROM `" . TABLE_PANEL_PHPCONFIGS . "` c
				LEFT JOIN `" . TABLE_PANEL_FPMDAEMONS . "` fc ON fc.id = c.fpmsettingid
				WHERE c.id IN (" . implode(", ", $allowed_cfg) . ")
			");
			while ($phpconfigs_row = $phpconfigs_result_stmt->fetch(\PDO::FETCH_ASSOC)) {
				if ((int) Settings::Get('phpfpm.enabled') == 1) {
					$phpconfigs .= \Froxlor\UI\HTML::makeoption($phpconfigs_row['description'] . " [" . $phpconfigs_row['interpreter'] . "]", $phpconfigs_row['id'], $sel_value, true, true);
				} else {
					$phpconfigs .= \Froxlor\UI\HTML::makeoption($phpconfigs_row['description'], $phpconfigs_row['id'], $sel_value, true, true);
				}
			}
		}

		if (! empty($result) && isset($result['domain'])) {
			$subdomain_data = include_once \Froxlor\Froxlor::getInstallDir() . '/lib/formfields/customer/domains/formfield.domains_edit.php';
		} else {
			$subdomain_data = include_once \Froxlor\Froxlor::getInstallDir() . '/lib/formfields/customer/domains/formfield.domains_add.php';
		}
		$subdomain_form = \Froxlor\UI\HtmlForm::genHTMLForm($subdomain_data);

		return $subdomain_form;
	}

	public function delete()
	{
		$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

		try {
			$json_result = SubDomains::getLocal(\Froxlor\CurrentUser::getData(), array(
				'id' => $id
			))->get();
		} catch (\Exception $e) {
			\Froxlor\UI\Response::dynamic_error($e->getMessage());
		}
		$result = json_decode($json_result, true)['data'];

		$alias_stmt = Database::prepare("SELECT COUNT(`id`) AS `count` FROM `" . TABLE_PANEL_DOMAINS . "` WHERE `aliasdomain` = :aliasdomain");
		$alias_check = Database::pexecute_first($alias_stmt, array(
			"aliasdomain" => $id
		));

		if (isset($result['parentdomainid']) && $result['parentdomainid'] != '0' && $alias_check['count'] == 0) {
			if (isset($_POST['send']) && $_POST['send'] == 'send') {
				try {
					SubDomains::getLocal(\Froxlor\CurrentUser::getData(), $_POST)->delete();
				} catch (\Exception $e) {
					\Froxlor\UI\Response::dynamic_error($e->getMessage());
				}
				\Froxlor\UI\Response::redirectTo("index.php?module=CustomerDomains");
			} else {
				$idna_convert = new \Froxlor\Idna\IdnaWrapper();
				\Froxlor\UI\HTML::askYesNo('domains_reallydelete', "index.php?module=CustomerDomains&view=" . __FUNCTION__, null, $idna_convert->decode($result['domain']));
			}
		} else {
			\Froxlor\UI\Response::standard_error('domains_cantdeletemaindomain');
		}
	}

	public function add()
	{
		if (\Froxlor\CurrentUser::getField('subdomains') == 0) {
			// no domains - not allowed
			\Froxlor\UI\Response::standard_error('noaccess', __METHOD__);
		}

		if (isset($_POST['send']) && $_POST['send'] == 'send') {
			try {
				SubDomains::getLocal(\Froxlor\CurrentUser::getData(), $_POST)->add();
			} catch (\Exception $e) {
				\Froxlor\UI\Response::dynamic_error($e->getMessage());
			}
		}
		\Froxlor\UI\Response::redirectTo('index.php?module=CustomerDomains');
	}

	public function edit()
	{
		$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
		try {
			$json_result = SubDomains::getLocal(\Froxlor\CurrentUser::getData(), array(
				'id' => $id
			))->get();
		} catch (\Exception $e) {
			\Froxlor\UI\Response::dynamic_error($e->getMessage());
		}
		$result = json_decode($json_result, true)['data'];

			if (isset($_POST['send']) && $_POST['send'] == 'send') {
				try {
					SubDomains::getLocal(\Froxlor\CurrentUser::getData(), $_POST)->update();
				} catch (\Exception $e) {
					\Froxlor\UI\Response::dynamic_error($e->getMessage());
				}
				\Froxlor\UI\Response::redirectTo("index.php?module=CustomerDomains");
			} else {

				$idna_convert = new \Froxlor\Idna\IdnaWrapper();
				$result['domain'] = $idna_convert->decode($result['domain']);

				$domain_edit_form = $this->domainForm($result);
				
				\Froxlor\Frontend\UI::TwigBuffer('customer/domains/domain.html.twig', array(
					'page_title' => $this->lng['domains']['subdomain_edit'],
					'domain' => $result,
					'form_data' => $domain_edit_form
				));
				
				/**
				// check if we at least have one ssl-ip/port, #1179
				$ssl_ipsandports = '';
				$ssl_ip_stmt = Database::prepare("
					SELECT COUNT(*) as countSSL
					FROM `" . TABLE_PANEL_IPSANDPORTS . "` pip
					LEFT JOIN `" . TABLE_DOMAINTOIP . "` dti ON dti.id_ipandports = pip.id
					WHERE `dti`.`id_domain` = :id_domain AND pip.`ssl`='1'
				");
				Database::pexecute($ssl_ip_stmt, array(
					"id_domain" => $result['id']
				));
				$resultX = $ssl_ip_stmt->fetch(\PDO::FETCH_ASSOC);
				if (isset($resultX['countSSL']) && (int) $resultX['countSSL'] > 0) {
					$ssl_ipsandports = 'notempty';
				}




				$ips_stmt = Database::prepare("SELECT `p`.`ip` AS `ip` FROM `" . TABLE_PANEL_IPSANDPORTS . "` `p`
					LEFT JOIN `" . TABLE_DOMAINTOIP . "` `dip`
					ON ( `dip`.`id_ipandports` = `p`.`id` )
					WHERE `dip`.`id_domain` = :id_domain
					GROUP BY `p`.`ip`");
				Database::pexecute($ips_stmt, array(
					"id_domain" => $result['id']
				));
				$result_ipandport['ip'] = '';
				while ($rowip = $ips_stmt->fetch(\PDO::FETCH_ASSOC)) {
					$result_ipandport['ip'] .= $rowip['ip'] . "<br />";
				}

				$phpconfigs = '';
				$has_phpconfigs = false;
				if (isset($userinfo['allowed_phpconfigs']) && ! empty($userinfo['allowed_phpconfigs'])) {
					$has_phpconfigs = true;
					$allowed_cfg = json_decode($userinfo['allowed_phpconfigs'], JSON_OBJECT_AS_ARRAY);
					$phpconfigs_result_stmt = Database::query("
						SELECT c.*, fc.description as interpreter
						FROM `" . TABLE_PANEL_PHPCONFIGS . "` c
						LEFT JOIN `" . TABLE_PANEL_FPMDAEMONS . "` fc ON fc.id = c.fpmsettingid
						WHERE c.id IN (" . implode(", ", $allowed_cfg) . ")
					");
					while ($phpconfigs_row = $phpconfigs_result_stmt->fetch(\PDO::FETCH_ASSOC)) {
						if ((int) Settings::Get('phpfpm.enabled') == 1) {
							$phpconfigs .= \Froxlor\UI\HTML::makeoption($phpconfigs_row['description'] . " [" . $phpconfigs_row['interpreter'] . "]", $phpconfigs_row['id'], $result['phpsettingid'], true, true);
						} else {
							$phpconfigs .= \Froxlor\UI\HTML::makeoption($phpconfigs_row['description'], $phpconfigs_row['id'], $result['phpsettingid'], true, true);
						}
					}
				}

				$domainip = $result_ipandport['ip'];
				$result = \Froxlor\PhpHelper::htmlentitiesArray($result);

				$subdomain_edit_data = include_once dirname(__FILE__) . '/lib/formfields/customer/domains/formfield.domains_edit.php';
				$subdomain_edit_form = \Froxlor\UI\HtmlForm::genHTMLForm($subdomain_edit_data);

				$title = $subdomain_edit_data['domain_edit']['title'];
				$image = $subdomain_edit_data['domain_edit']['image'];

				eval("echo \"" . \Froxlor\UI\Template::getTemplate("domains/domains_edit") . "\";");
				*/
			}
	}

	public function domainSslEditor()
	{
		$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

		if (isset($_POST['send']) && $_POST['send'] == 'send') {
			$do_insert = isset($_POST['do_insert']) ? (($_POST['do_insert'] == 1) ? true : false) : false;
			try {
				if ($do_insert) {
					Certificates::getLocal(\Froxlor\CurrentUser::getData(), $_POST)->add();
				} else {
					Certificates::getLocal(\Froxlor\CurrentUser::getData(), $_POST)->update();
				}
			} catch (\Exception $e) {
				\Froxlor\UI\Response::dynamic_error($e->getMessage());
			}
			// back to domain overview
			\Froxlor\UI\Response::redirectTo("index.php?module=CustomerDomains");
		}

		$stmt = Database::prepare("
			SELECT * FROM `" . TABLE_PANEL_DOMAIN_SSL_SETTINGS . "`
			WHERE `domainid`= :domainid
		");
		$result = Database::pexecute_first($stmt, array(
			"domainid" => $id
		));

		$do_insert = false;
		// if no entry can be found, behave like we have empty values
		if (! is_array($result) || ! isset($result['ssl_cert_file'])) {
			$result = array(
				'ssl_cert_file' => '',
				'ssl_key_file' => '',
				'ssl_ca_file' => '',
				'ssl_cert_chainfile' => ''
			);
			$do_insert = true;
		}

		$result = \Froxlor\PhpHelper::htmlentitiesArray($result);

		$ssleditor_data = include_once dirname(__FILE__) . '/lib/formfields/customer/domains/formfield.domain_ssleditor.php';
		$ssleditor_form = \Froxlor\UI\HtmlForm::genHTMLForm($ssleditor_data);

		$title = $ssleditor_data['domain_ssleditor']['title'];
		$image = $ssleditor_data['domain_ssleditor']['image'];

		eval("echo \"" . \Froxlor\UI\Template::getTemplate("domains/domain_ssleditor") . "\";");
	}
}
