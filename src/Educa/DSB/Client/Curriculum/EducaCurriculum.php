<?php

/**
 * @file
 * Contains \Educa\DSB\Client\Curriculum\EducaCurriculum.
 */

namespace Educa\DSB\Client\Curriculum;

use Educa\DSB\Client\Utils;
use Educa\DSB\Client\Curriculum\Term\TermInterface;
use Educa\DSB\Client\Curriculum\Term\EducaTerm;
use Educa\DSB\Client\Curriculum\CurriculumInvalidContextException;

class EducaCurriculum extends BaseCurriculum
{

    const CURRICULUM_JSON = 'curriculum json';

    /**
     * The list of all terms, with their associated term type.
     *
     * @var array
     */
    protected $curriculumDictionary;

    /**
     * The sources of taxonomy paths that can be treated by this class.
     *
     * @var array
     */
    protected $taxonPathSources = array('educa');

    /**
     * {@inheritdoc}
     *
     * @param string $context
     *    A context, explaining what kind of data this is. Possible contexts:
     *    - EducaCurriculum::CURRICULUM_JSON: Representation of the curriculum
     *      structure, in JSON. This information can be found on the bsn
     *      Ontology server.
     */
    public static function createFromData($data, $context = self::CURRICULUM_JSON)
    {
        switch ($context) {
            case self::CURRICULUM_JSON:
                $data = self::parseCurriculumJson($data);
                $curriculum = new EducaCurriculum($data->curriculum);
                $curriculum->setCurriculumDictionary($data->dictionary);
                return $curriculum;
        }

        // @codeCoverageIgnoreStart
        throw new CurriculumInvalidContextException();
        // @codeCoverageIgnoreEnd
    }

    /**
     * {@inheritdoc}
     *
     * @codeCoverageIgnore
     */
    public function describeDataStructure()
    {
        return array(
            (object) array(
                'type' => 'educa_school_levels',
                'childTypes' => array('context'),
            ),
            (object) array(
                'type' => 'context',
                'childTypes' => array('school_level'),
            ),
            (object) array(
                'type' => 'school_level',
                'childTypes' => array('school_level'),
            ),
            (object) array(
                'type' => 'educa_school_subjects',
                'childTypes' => array('discipline'),
            ),
            (object) array(
                'type' => 'discipline',
                'childTypes' => array('discipline'),
            ),
        );
    }

    /**
     * {@inheritdoc}
     *
     * @codeCoverageIgnore
     */
    public function describeTermTypes()
    {
        return array(
            (object) array(
                'type' => 'context',
                'purpose' => array(
                    'LOM-CHv1.2' => 'educational level',
                ),
            ),
            (object) array(
                'type' => 'school_level',
                'purpose' => array(
                    'LOM-CHv1.2' => 'educational level',
                ),
            ),
            (object) array(
                'type' => 'discipline',
                'purpose' => array(
                    'LOM-CHv1.2' => 'discipline',
                ),
            ),
        );
    }

    /**
     * Parse the curriculum definition file.
     *
     * By passing the official curriculum definition file (JSON), this method
     * will parse it and return a curriculum definition it can understand and
     * treat. It mainly needs a "dictionary" of term types. See
     * \Educa\DSB\Client\Curriculum\EducaCurriculum::setCurriculumDictionary().
     *
     * @param string $curriculumJson
     *    The curriculum definition file, in JSON.
     *
     * @return array
     *    An object with 2 properties:
     *    - curriculum: A parsed and prepared curriculum tree. It uses
     *      Educa\DSB\Client\Curriculum\Term\TermInterface elements to define
     *      the curriculum tree.
     *    - dictionary: A dictionary of term identifiers, with name and type
     *      information for each one of them.
     *
     * @see \Educa\DSB\Client\Curriculum\EducaCurriculum::setCurriculumDictionary()
     */
    public static function parseCurriculumJson($curriculumJson)
    {
        $data = json_decode($curriculumJson);

        // Prepare the dictionary.
        $dictionary = array();

        // Prepare a list of items. This will make the creation of our
        // curriculum tree easier to manage.
        $list = array();

        $root = new EducaTerm('root', 'root');

        foreach ($data->vocabularies as $vocabulary) {
            $dictionary[$vocabulary->identifier] = (object) array(
                'name' => $vocabulary->name,
                'type' => $vocabulary->identifier
            );

            $list[$vocabulary->identifier]['root'] = new EducaTerm(
                $vocabulary->identifier,
                $vocabulary->identifier,
                $vocabulary->name,
                'LOM-CHv1.0'
            );

            $root->addChild($list[$vocabulary->identifier]['root']);

            foreach ($vocabulary->terms as $term) {
                if (!empty($term->deprecated)) {
                    continue;
                }

                $type = static::parseCurriculumJsonGetType($vocabulary, $term);

                // Store the term definition in the dictionary.
                $dictionary[$term->identifier] = (object) array(
                    'name' => $term->name,
                    'type' => $type,
                    'context' => $term->context
                );

                // Did we already create this term, on a temporary basis?
                if (isset($list[$vocabulary->identifier][$term->identifier])) {
                    // We need to "enhance" it now with its actual
                    // information.
                    $item = $list[$vocabulary->identifier][$term->identifier];
                    $item->setDescription($type, $term->identifier, $term->name);
                } else {
                    // Prepare the term element.
                    $item = new EducaTerm($type, $term->identifier, $term->name, $term->context);
                    $list[$vocabulary->identifier][$term->identifier] = $item;
                }

                // Does it have a parent?
                if (!empty($term->parents)) {
                    // Now, we may not have found the parent yet. Check if
                    // we already have the parent item ready. Even though
                    // the parents property is an array, in practice there
                    // is always a single parent, so we can safely treat the
                    // first key.
                    if (isset($list[$vocabulary->identifier][$term->parents[0]])) {
                        // Found the parent.
                        $parent = $list[$vocabulary->identifier][$term->parents[0]];
                    } else {
                        // There is no parent item ready yet. We need to
                        // create a temporary one, which will be enhanced as
                        // soon as we reach the actual parent term.
                        $parent = new EducaTerm('temp', 'temp');

                        // Store it already; later, we will update its
                        // description data.
                        $list[$vocabulary->identifier][$term->parents[0]] = $parent;
                    }
                } else {
                    // If not, we add it to the root.
                    $parent = $list[$vocabulary->identifier]['root'];
                }

                $parent->addChild($item);
            }
        }

        return (object) array(
            'curriculum' => $root,
            'dictionary' => $dictionary,
        );
    }

    /**
     * Determine the type of a term when parsing.
     *
     * @param object $vocabulary
     * @param object $term
     *
     * @return string
     */
    protected static function parseCurriculumJsonGetType($vocabulary, $term) {
        if ($vocabulary->identifier == 'educa_school_levels') {
            return !empty($term->parents) ? 'school_level' : 'context';
        } else {
            return 'discipline';
        }
    }

    /**
     * Set the curriculum dictionary.
     *
     * @param array $dictionary
     *
     * @return this
     *
     * @see \Educa\DSB\Client\Curriculum\EducaCurriculum::parseCurriculumJson().
     */
    public function setCurriculumDictionary($dictionary)
    {
        $this->curriculumDictionary = $dictionary;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getTermType($identifier)
    {
        return isset($this->curriculumDictionary[$identifier]) ? $this->curriculumDictionary[$identifier]->type : 'n/a';
    }

    /**
     * {@inheritdoc}
     */
    public function getTermName($identifier)
    {
        return isset($this->curriculumDictionary[$identifier]->name) ? $this->curriculumDictionary[$identifier]->name : 'n/a';
    }

    /**
     * {@inheritdoc}
     */
    protected function taxonIsDiscipline($taxon)
    {
        // First check the parent implementation. If it is false, use a legacy
        // method.
        if (parent::taxonIsDiscipline($taxon)) {
            return true;
        } else {
            return $this->getTermType($taxon['id']) === 'discipline';
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function termFactory($type, $taxonId, $name = null)
    {
        $context = null;
        if (isset($this->curriculumDictionary[$taxonId])) {
            $definition = $this->curriculumDictionary[$taxonId];

            if (isset($definition->context)) {
                $context = $definition->context;
            }

            // Always fetch the name from the local data. The data passed may be
            // stale, as it usually comes from the dsb API. Normally, local data
            // is refreshed on regular bases, so should be more up-to-date.
            if (isset($definition->name)) {
                $name = $definition->name;
            }
        }
        return new EducaTerm($type, $taxonId, $name, $context);
    }
}
