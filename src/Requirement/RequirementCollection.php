<?php

namespace Bolt\Installer\Requirement;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

/**
 * A RequirementCollection represents a set of Requirement instances.
 *
 * @author Tobias Schultze <http://tobion.de>
 */
class RequirementCollection implements IteratorAggregate
{
    /**
     * @var Requirement[]
     */
    private $requirements = [];

    /**
     * Gets the current RequirementCollection as an Iterator.
     *
     * @return Traversable A Traversable interface
     */
    public function getIterator()
    {
        return new ArrayIterator($this->requirements);
    }

    /**
     * Adds a Requirement.
     *
     * @param Requirement $requirement A Requirement instance
     */
    public function add(Requirement $requirement)
    {
        $this->requirements[] = $requirement;
    }

    /**
     * Adds a mandatory requirement.
     *
     * @param bool        $fulfilled   Whether the requirement is fulfilled
     * @param string      $testMessage The message for testing the requirement
     * @param string      $helpHtml    The help text formatted in HTML for resolving the problem
     * @param string|null $helpText    The help text (when null, it will be inferred from $helpHtml, i.e. stripped from HTML tags)
     */
    public function addRequirement($fulfilled, $testMessage, $helpHtml, $helpText = null)
    {
        $this->add(new Requirement($fulfilled, $testMessage, $helpHtml, $helpText, false));
    }

    /**
     * Adds an optional recommendation.
     *
     * @param bool        $fulfilled   Whether the recommendation is fulfilled
     * @param string      $testMessage The message for testing the recommendation
     * @param string      $helpHtml    The help text formatted in HTML for resolving the problem
     * @param string|null $helpText    The help text (when null, it will be inferred from $helpHtml, i.e. stripped from HTML tags)
     */
    public function addRecommendation($fulfilled, $testMessage, $helpHtml, $helpText = null)
    {
        $this->add(new Requirement($fulfilled, $testMessage, $helpHtml, $helpText, true));
    }

    /**
     * Adds a mandatory requirement in form of a php.ini configuration.
     *
     * @param string        $cfgName           The configuration name used for ini_get()
     * @param bool|callback $evaluation        Either a boolean indicating whether the configuration should evaluate to true or false,
     *                                         or a callback function receiving the configuration value as parameter to determine the fulfillment of the requirement
     * @param bool          $approveCfgAbsence If true the Requirement will be fulfilled even if the configuration option does not exist, i.e. ini_get() returns false.
     *                                         This is helpful for abandoned configs in later PHP versions or configs of an optional extension, like Suhosin.
     *                                         Example: You require a config to be true but PHP later removes this config and defaults it to true internally.
     * @param string        $testMessage       The message for testing the requirement (when null and $evaluation is a boolean a default message is derived)
     * @param string        $helpHtml          The help text formatted in HTML for resolving the problem (when null and $evaluation is a boolean a default help is derived)
     * @param string|null   $helpText          The help text (when null, it will be inferred from $helpHtml, i.e. stripped from HTML tags)
     */
    public function addPhpIniRequirement($cfgName, $evaluation, $approveCfgAbsence = false, $testMessage = null, $helpHtml = null, $helpText = null)
    {
        $this->add(new PhpIniRequirement($cfgName, $evaluation, $approveCfgAbsence, $testMessage, $helpHtml, $helpText, false));
    }

    /**
     * Adds an optional recommendation in form of a php.ini configuration.
     *
     * @param string        $cfgName           The configuration name used for ini_get()
     * @param bool|callback $evaluation        Either a boolean indicating whether the configuration should evaluate to true or false,
     *                                         or a callback function receiving the configuration value as parameter to determine the fulfillment of the requirement
     * @param bool          $approveCfgAbsence If true the Requirement will be fulfilled even if the configuration option does not exist, i.e. ini_get() returns false.
     *                                         This is helpful for abandoned configs in later PHP versions or configs of an optional extension, like Suhosin.
     *                                         Example: You require a config to be true but PHP later removes this config and defaults it to true internally.
     * @param string        $testMessage       The message for testing the requirement (when null and $evaluation is a boolean a default message is derived)
     * @param string        $helpHtml          The help text formatted in HTML for resolving the problem (when null and $evaluation is a boolean a default help is derived)
     * @param string|null   $helpText          The help text (when null, it will be inferred from $helpHtml, i.e. stripped from HTML tags)
     */
    public function addPhpIniRecommendation($cfgName, $evaluation, $approveCfgAbsence = false, $testMessage = null, $helpHtml = null, $helpText = null)
    {
        $this->add(new PhpIniRequirement($cfgName, $evaluation, $approveCfgAbsence, $testMessage, $helpHtml, $helpText, true));
    }

    /**
     * Adds a requirement collection to the current set of requirements.
     *
     * @param RequirementCollection $collection A RequirementCollection instance
     */
    public function addCollection(RequirementCollection $collection)
    {
        $this->requirements = array_merge($this->requirements, $collection->all());
    }

    /**
     * Returns both requirements and recommendations.
     *
     * @return Requirement[]
     */
    public function all()
    {
        return $this->requirements;
    }

    /**
     * Returns all mandatory requirements.
     *
     * @return Requirement[]
     */
    public function getRequirements()
    {
        $array = [];
        foreach ($this->requirements as $req) {
            if (!$req->isOptional()) {
                $array[] = $req;
            }
        }

        return $array;
    }

    /**
     * Returns the mandatory requirements that were not met.
     *
     * @return Requirement[]
     */
    public function getFailedRequirements()
    {
        $array = [];
        foreach ($this->requirements as $req) {
            if (!$req->isFulfilled() && !$req->isOptional()) {
                $array[] = $req;
            }
        }

        return $array;
    }

    /**
     * Returns all optional recommendations.
     *
     * @return Requirement[]
     */
    public function getRecommendations()
    {
        $array = [];
        foreach ($this->requirements as $req) {
            if ($req->isOptional()) {
                $array[] = $req;
            }
        }

        return $array;
    }

    /**
     * Returns the recommendations that were not met.
     *
     * @return Requirement[]
     */
    public function getFailedRecommendations()
    {
        $array = [];
        foreach ($this->requirements as $req) {
            if (!$req->isFulfilled() && $req->isOptional()) {
                $array[] = $req;
            }
        }

        return $array;
    }

    /**
     * Returns whether a php.ini configuration is not correct.
     *
     * @return bool php.ini configuration problem?
     */
    public function hasPhpIniConfigIssue()
    {
        foreach ($this->requirements as $req) {
            if (!$req->isFulfilled() && $req instanceof PhpIniRequirement) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns the PHP configuration file (php.ini) path.
     *
     * @return string|false php.ini file path
     */
    public function getPhpIniConfigPath()
    {
        return get_cfg_var('cfg_file_path');
    }
}