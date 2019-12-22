<?php
namespace Bibliography;

if (!class_exists(\Generic\AbstractModule::class)) {
    require file_exists(dirname(__DIR__) . '/Generic/AbstractModule.php')
        ? dirname(__DIR__) . '/Generic/AbstractModule.php'
        : __DIR__ . '/src/Generic/AbstractModule.php';
}

use Generic\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\ModuleManager\ModuleManager;

/**
 * Bibliography
 *
 * Tools to manage bibliographic items.
 *
 * @copyright Daniel Berthereau, 2018-2019
 * @license http://www.cecill.info/licences/Licence_CeCILL_V2.1-en.txt
 */
class Module extends AbstractModule
{
    const NAMESPACE = __NAMESPACE__;

    public function init(ModuleManager $moduleManager)
    {
        require_once __DIR__ . '/vendor/autoload.php';
    }

    protected function postInstall()
    {
        $this->uninstallModuleCitation();
        $this->installResources();
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            'Omeka\Controller\Site\Item',
            'view.show.after',
            [$this, 'handleViewShowAfter']
        );

        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_elements',
            [$this, 'handleMainSettings']
        );
        $sharedEventManager->attach(
            \Omeka\Form\SettingForm::class,
            'form.add_input_filters',
            [$this, 'handleMainSettingsFilters']
        );

        $sharedEventManager->attach(
            \Omeka\Form\SiteSettingsForm::class,
            'form.add_elements',
            [$this, 'handleSiteSettings']
        );
        $sharedEventManager->attach(
            \Omeka\Form\SiteSettingsForm::class,
            'form.add_input_filters',
            [$this, 'handleSiteSettingsFilters']
        );
    }

    public function handleMainSettingsFilters(Event $event)
    {
        $event->getParam('inputFilter')
            ->get('bibliography')
            ->add([
                'name' => 'bibliography_csl_style',
                'required' => false,
            ])
            ->add([
                'name' => 'bibliography_csl_locale',
                'required' => false,
            ]);
    }

    public function handleSiteSettingsFilters(Event $event)
    {
        $inputFilter = $event->getParam('inputFilter');
        $inputFilter->get('bibliography')
            ->add([
                'name' => 'bibliography_csl_style',
                'required' => false,
            ])
            ->add([
                'name' => 'bibliography_csl_locale',
                'required' => false,
            ]);
    }

    public function handleViewShowAfter(Event $event)
    {
        $view = $event->getTarget();
        echo $view->citation($view->resource, ['tag' => 'p']);
    }

    protected function uninstallModuleCitation()
    {
        $services = $this->getServiceLocator();
        /** @var \Omeka\Module\Manager $moduleManager */
        $moduleManager = $services->get('Omeka\ModuleManager');
        $module = $moduleManager->getModule('Citation');
        if (!$module) {
            return;
        }

        $state = $module->getState();
        if (!in_array($state, [
            \Omeka\Module\Manager::STATE_ACTIVE,
            \Omeka\Module\Manager::STATE_NOT_ACTIVE,
            \Omeka\Module\Manager::STATE_NOT_FOUND,
            \Omeka\Module\Manager::STATE_NEEDS_UPGRADE,
            \Omeka\Module\Manager::STATE_INVALID_OMEKA_VERSION,
        ])) {
            return;
        }

        $t = $services->get('MvcTranslator');
        $messenger = new \Omeka\Mvc\Controller\Plugin\Messenger();

        // Process uninstallation directly: the module has nothing to uninstall.
        $entityManager = $services->get('Omeka\EntityManager');
        $entity = $entityManager
            ->getRepository(\Omeka\Entity\Module::class)
            ->findOneById($module->getId());
        if (!$entity) {
            $message = new \Omeka\Stdlib\Message(
                $t->translate('The module Bibliography replaces the module Citation, that cannot be automatically uninstalled.') // @translate
            );
            $messenger->addWarning($message);
            return;
        }

        $entityManager->remove($entity);
        $entityManager->flush();

        $message = new \Omeka\Stdlib\Message(
            $t->translate('The module Bibliography replaces the module Citation, that was automatically uninstalled.') // @translate
        );
        $messenger->addNotice($message);

        $module->setState(\Omeka\Module\Manager::STATE_NOT_INSTALLED);
    }

    protected function installResources()
    {
        if (!class_exists(\Generic\InstallResources::class)) {
            require_once file_exists(dirname(__DIR__) . '/Generic/InstallResources.php')
                ? dirname(__DIR__) . '/Generic/InstallResources.php'
                : __DIR__ . '/src/Generic/InstallResources.php';
        }

        $services = $this->getServiceLocator();
        $installResources = new \Generic\InstallResources($services);
        $installResources = $installResources();

        $vocabulary = [
            'vocabulary' => [
                'o:namespace_uri' => 'http://purl.org/spar/fabio/',
                'o:prefix' => 'fabio',
                'o:label' => 'FaBiO', // @translate
                'o:comment' => 'FaBiO, the FRBR-aligned Bibliographic Ontology', // @translate
            ],
            'strategy' => 'file',
            'file' => __DIR__ . '/data/vocabularies/fabio_2019-02-19.ttl',
            'format' => 'turtle',
        ];
        // This vocabulary is too big to be imported directly, so use sql.
        // @todo Creation vocabulary directly with pull request https://github.com/omeka/omeka-s/pull/1335
        // $installResources->createVocabulary($vocabulary);
        $this->createVocabularyViaSql();
    }

    protected function createVocabularyViaSql()
    {
        $namespace = 'http://purl.org/spar/fabio/';

        $services = $this->getServiceLocator();
        $connection = $services->get('Omeka\Connection');

        $api = $services->get('ControllerPluginManager')->get('api');
        $vocabulary = $api->searchOne('vocabularies', ['namespace_uri' => $namespace])->getContent();
        if ($vocabulary) {
            return;
        }

        $sql = <<<SQL
INSERT INTO `vocabulary` (`owner_id`, `namespace_uri`, `prefix`, `label`, `comment`) VALUES
(1, "$namespace", 'fabio', 'FaBiO, the FRBR-aligned Bibliographic Ontology', NULL);
SQL;
        $connection->exec($sql);

        $vocabularyId = $api->searchOne('vocabularies', ['namespace_uri' => $namespace])->getContent()->id();

        $sql = <<<SQL
INSERT INTO `resource_class` (`owner_id`, `vocabulary_id`, `local_name`, `label`, `comment`) VALUES
(1, $vocabularyId, 'Abstract', 'abstract', 'A brief summary of a work on a particular subject, designed to act as the point-of-entry that will help the reader quickly to obtain an overview of the work\'s contents.   The abstract may be an integral part of the work itself, written by the same author(s) and appearing at the beginning of a work such as a research paper, report, review or thesis.  Alternatively it may be separate from the published work itself, and written by someone other than the author(s) of the published work, for example by a member of a professional abstracting service such as CAB Abstracts.'),
(1, $vocabularyId, 'AcademicProceedings', 'academic proceedings', 'A document containing the programme and collected papers, or their abstracts, presented at an academic meeting.'),
(1, $vocabularyId, 'Addendum', 'addendum', 'An item of material added at the end of a book or other publication, typically to include omitted or late-arriving material. '),
(1, $vocabularyId, 'Algorithm', 'algorithm', 'A precise sequential set of pre-defined logical rules or computational operations to be employed for solving a particular problem in a finite number of steps.'),
(1, $vocabularyId, 'AnalogItem', 'analog item', 'A real object that is an exemplar of a fabio:Manifestation, such as a particular copy of the book \'Alice\'s adventures in Wonderland\', that a person may own.'),
(1, $vocabularyId, 'AnalogManifestation', 'analog manifestation', 'A manifestation in an analog form.'),
(1, $vocabularyId, 'AnalogStorageMedium', 'analog storage medium', 'A means of storing information in non-digital form, e.g. paper, film (for analogue photographs or movies), magnetic tape (for analogue sound recordings or video recordings) or vinyl disc.'),
(1, $vocabularyId, 'Announcement', 'announcement', 'A formal statement about something.'),
(1, $vocabularyId, 'Anthology', 'anthology', 'A collection of selected literary or scholastics works, for example poems, short stories, plays or research papers.'),
(1, $vocabularyId, 'ApplicationProfile', 'application profile', 'A set of metadata elements, policies and guidelines defined for a particular application.  The metadata elements used in the application profile may be drawn from more than one element sets, including locally defined sets. '),
(1, $vocabularyId, 'ApplicationProgrammingInterface', 'API', ' A computer program that enables a separate computer to interact programmatically with the computer running the API.  (Commonly abbreviated \'API\'.)'),
(1, $vocabularyId, 'ArchivalDocument', 'archival document', 'An archival document is a realization of the content related to an archival record. It can be exemplified as a book, a document, a letter, a database, etc.'),
(1, $vocabularyId, 'ArchivalDocumentSet', 'archival document set', 'A collection of archival document.'),
(1, $vocabularyId, 'ArchivalRecord', 'archival record', 'An archival record connotes a material created or received by a person, family, or organization, public or private, in the conduct of their affairs that is preserved because of the enduring value contained in the information it contains or as evidence of the function and the responsibilities of its creator.'),
(1, $vocabularyId, 'ArchivalRecordSet', 'archival record set', 'A collection of archival records.'),
(1, $vocabularyId, 'Article', 'article', 'The realization of a piece of writing on a particular topic, usually published within a periodical publication (e.g. journal, magazine and newspaper).'),
(1, $vocabularyId, 'ArtisticWork', 'artistic work', 'It describes any work regarded as art in its widest sense, including works from literature and music, visual art, etc.'),
(1, $vocabularyId, 'AudioDocument', 'audio document', 'The realization of a sound recording.'),
(1, $vocabularyId, 'AuthorityFile', 'authority file', 'A controlled vocabulary or official list that establishes, for consistency, the authoritative forms of headings, and the preferred terms or proper names to be used, when creating a catalogue or when indexing and searching a set of entities within a defined domain.'),
(1, $vocabularyId, 'BachelorsThesis', 'bachelor\'s thesis', 'A thesis reporting a research project undertaken as part of an undergraduate course of education leading to a bachelor\'s degree.'),
(1, $vocabularyId, 'BibliographicDatabase', 'bibliographic database', 'A database providing an authoritative source of bibliographic information, for example PubMed (http://www.ncbi.nlm.nih.gov/pubmed), CrossRef Metadata Search (http://search.crossref.org/) and PubMed Central (http://www.ncbi.nlm.nih.gov/pmc/).'),
(1, $vocabularyId, 'BibliographicMetadata', 'bibliographic metadata', 'Standard bibliographic metadata describing an expression of a work.  To take the example of a journal article, bibliographic metadata typically include the authors\' names, the date of publication, the title of the article, the journal name and volume number, the first and last page numbers, and the Digital Object Identifier (DOI).'),
(1, $vocabularyId, 'Biography', 'biography', 'An account of the events, works and achievements, both personal and professional, of a person, either living or dead.'),
(1, $vocabularyId, 'Blog', 'blog', 'A Web publication medium containing blog posts.'),
(1, $vocabularyId, 'BlogPost', 'blog post', 'Information manifested in a blog, one of a set of periodic sequential entries containing commentary, descriptions of events, or other material such as images or videos, usually displayed in reverse-chronological order and usually maintained by an individual, or comments on such a post.'),
(1, $vocabularyId, 'Book', 'book', 'A non-serial document that is complete in one volume or a designated finite number of volumes.  A book published by a publisher is usually  identified by an International Standard Book Number (ISBN), and may be manifested as a physical printed publication on paper bound in a hard or soft cover, or in electronic format as an \'e-book\'.'),
(1, $vocabularyId, 'BookChapter', 'book chapter', 'A defined chapter of a book, usually with a separate title or number.'),
(1, $vocabularyId, 'BookReview', 'book review', 'A written review and critical analysis of the content, scope and quality of a book or other monographic work.'),
(1, $vocabularyId, 'BookSeries', 'book series', 'A sequence of books having certain characteristics in common that are formally identified together as a group - for instance, the books in the Law, Governance and Technology Series published by Springer.'),
(1, $vocabularyId, 'BookSet', 'book set', 'A set of books having certain characteristics in common that informally allow their identification together as a group - for instance, the books of the Harry Potter saga.'),
(1, $vocabularyId, 'BriefReport', 'brief report', 'A brief report document.  This term may also be used synonymously with Rapid Communication to mean \'A short rapidly published research article or conference paper, typically reporting significant research results that have been recently discovered, or a brief news item reporting such discoveries.\''),
(1, $vocabularyId, 'CallForApplications', 'call for applications', 'A document published by a funding agency requesting submission of applications for financial grants to fund projects, for example to enable research investigations in areas specified in the Call.'),
(1, $vocabularyId, 'CaseForSupport', 'case for support', 'A part of a grant application that provides a description of a proposed project and gives reasons why it is worthy of funding. (See also fabio:GrantApplication).'),
(1, $vocabularyId, 'CaseForSupportDocument', 'case for support document', 'A document containing the case for support for a particular project, usually contained within a grant application document but sometimes distributed separately, without the financial and organizational information that the grant application document also contains.'),
(1, $vocabularyId, 'CaseReport', 'case report', 'A report about a particular case or situation.'),
(1, $vocabularyId, 'Catalog', 'catalog', 'A list of items describing the content of a resource, for example items in an exhibition, items offered for sale by a vendor, or entities contained within a library or collection.  Ideally, catalogs are created according to specific and uniform principles of construction and are under the control of an authority file.'),
(1, $vocabularyId, 'Chapter', 'chapter', 'A defined document section, forming part of or intended for inclusion within a larger document, usually with its own title or chapter number.  Different chapters within a document such as a book or a report may each be independently authored, or may all be authored by a single individual or group of authors.'),
(1, $vocabularyId, 'CitationMetadata', 'citation metadata', 'Metadata describing the citations made within a work to other works, and (optionally) some characteristics of the expressions of the cited works.'),
(1, $vocabularyId, 'ClinicalCaseReport', 'clinical case report', 'A presentation of findings following a clinical or medical investigation on a human or animal patient, that may contain a diagnosis and proposals for therapeutic treatment and/or epidemiological control measures, or may propose further evaluative studies that will eventually lead to such outcomes.'),
(1, $vocabularyId, 'ClinicalGuideline', 'clinical guideline', 'A recommendation on the appropriate treatment and care of people with a specific disease or condition, based on the best available evidence, designed to help healthcare professionals in their work.'),
(1, $vocabularyId, 'ClinicalTrialDesign', 'clinical trial design', 'A predefined written procedural method, designed to ensure reliability of findings, for undertaking a medical or veterinary clinical study of the safety, efficacy, or optimum dosage schedule of one or more diagnostic, therapeutic or prophylactic drugs or treatments, or of devices or techniques, involving a randomized controlled trial for evidence-based assessment in humans or animals, specifying criteria of eligibility, nature of controls, sampling schedules, data collection parameters, statistical analyses, reporting standards, etc. to be employed in undertaking the clinical trial.'),
(1, $vocabularyId, 'ClinicalTrialReport', 'clinical trial report', 'The report of a pre-planned medical or veterinary clinical study of the safety, efficacy, or optimum dosage schedule of one or more diagnostic, therapeutic or prophylactic drugs, or of devices, treatments or techniques, involving a randomized controlled trial for evidence-based assessment in humans or animals selected according to predetermined criteria of eligibility and observed for evidence of favourable and unfavourable effects.'),
(1, $vocabularyId, 'CollectedWorks', 'collected works', 'A collection of the literary or scholastic works of a single person.'),
(1, $vocabularyId, 'Comment', 'comment', 'A verbal or written remark concerning some entity.  In written form, a comment is often appended to that entity and termed an annotation.  Within computer programs or ontologies, comments are added to enhance human understanding, and are usually prefaced by a special syntactic symbol that ensures they are ignored during execution of the program.'),
(1, $vocabularyId, 'CompleteWorks', 'complete works', 'A collection of all the literary or scholastic works of a single person.'),
(1, $vocabularyId, 'ComputerApplication', 'computer application', 'A computer program designed to assist a human user to perform one or more goal-oriented tasks such as word processing or image processing.  A computer application will typically save its output files in one or more specific formats, conforming either to proprietary or open standards.  '),
(1, $vocabularyId, 'ComputerFile', 'computer file', 'A digital item containing information in computer-readable form encoded in a particular format.'),
(1, $vocabularyId, 'ComputerProgram', 'computer program', 'A unit of computer code in source or compiled form, employing one or more algorithms to be executed by a digital computer to undertake a particular task.  Computer programs are collectively called \'software\' to distinguish them from the equipment (\'hardware\') upon which they run. '),
(1, $vocabularyId, 'ConferencePaper', 'conference paper', 'A paper, typically the realization of a research paper reporting original research findings, usually published within a conference proceedings volume.'),
(1, $vocabularyId, 'ConferencePoster', 'conference poster', 'A display poster, typically containing text with illustrative figures and/or tables, usually reporting research results or proposing hypotheses, submitted for acceptance to and/or presented at a conference, seminar, symposium, workshop or similar event.'),
(1, $vocabularyId, 'ConferenceProceedings', 'conference proceedings', 'A document containing the programme and collected conference papers, or their abstracts, presented at a conference, seminar, symposium or similar event.'),
(1, $vocabularyId, 'ControlledVocabulary', 'controlled vocabulary', 'A collection of selected words and phrases related to a particular domain of knowledge used to permit consistency of metadata annotation and improved retrieval following a search, in which homonyms, synonyms and similar ambiguities of meaning present in natural language are disambiguated.'),
(1, $vocabularyId, 'Correction', 'correction', 'A correction to an error in a previously published document.'),
(1, $vocabularyId, 'Corrigendum', 'corrigendum', 'A formal correction to an error introduced by the author into a previously published document.'),
(1, $vocabularyId, 'Cover', 'cover', 'A protective covering used to bind together the pages of a document or the first, informative, page of a digital document.'),
(1, $vocabularyId, 'CriticalEdition', 'critical edition', 'A new edition of a historical publication, edited by a scholar other than the original author, containing within the body text the supposedly best version of the original work, with footnotes detailing and commenting on textual variations between different versions, typically with an introduction to the original work written by the scholar, and with a bibliography listing related publications.'),
(1, $vocabularyId, 'DataFile', 'data file', 'A realisation of a fabio:Dataset (a frbr:Work) containing a defined collection of data with specific content and possibly with a specific version number, that can be embodied as a fabio:Digital Manifestation (a frbr:Manifestation with a specific format) and be represented by a specific fabio:ComputerFile (a frbr:Item) on someone\'s hard drive.'),
(1, $vocabularyId, 'DataManagementPolicy', 'data management policy', 'A policy that descibes and defines how data should be managed, preserved and shared.'),
(1, $vocabularyId, 'DataManagementPolicyDocument', 'data management policy document', 'A document embodying a policy that descibes and defines how data should be managed, preserved and shared.'),
(1, $vocabularyId, 'DataMangementPlan', 'data management plan', 'A structured document giving information about how data arising from a research project or other endeavour is to be manages, preserved and shared.'),
(1, $vocabularyId, 'DataRepository', 'data repository', 'A repository for storing data.'),
(1, $vocabularyId, 'Database', 'database', 'A structured collection of logically related records or data usually stored and retrieved using computer-based means.'),
(1, $vocabularyId, 'DatabaseManagementSystem', 'database management system', 'The software used to create a database.  (Commonly abbreviated \'DBMS\'.)'),
(1, $vocabularyId, 'Dataset', 'dataset', 'A collection of related facts, often expressed in numerical form and encoded in a defined structure.'),
(1, $vocabularyId, 'DefinitiveVersion', 'definitive version', 'The final published expression of a work that bears the publisher\'s imprimatur. Typically for a journal article, the Definitive Version results from revision of an earlier submitted version of the work following peer review, and is then published in print and/or digital form after the publisher has assigned it a DOI.  The Definitive Version is also known as the Version of Record, although according to the CrossRef Glossary (http://crossref.org/02publishers/glossary.html) that term can also refer to the author\'s final version of a work that is not formally published. '),
(1, $vocabularyId, 'DemoPaper', 'demo paper', 'A demonstration paper, typically describing a new product, service or system created as a result of research, usually presented during a conference or workshop.'),
(1, $vocabularyId, 'Diary', 'diary', 'A personal record, in a form of book, with discrete entries (often handwritten) arranged by date, reporting what has happened over the course of a day or other period of time.'),
(1, $vocabularyId, 'DigitalItem', 'digital item', 'A digital object, such as a computer file.'),
(1, $vocabularyId, 'DigitalManifestation', 'digital manifestation', 'A manifestation that represents data in binary form, encoding the data as a series of 0s and 1s.'),
(1, $vocabularyId, 'DigitalStorageMedium', 'digital storage medium', 'A means of storing information in digital form, involving binary encoding of data in 0s and 1s, e.g. a computer random access memory, hard disc, USB stick, CD, DVD or digital magnetic tape.'),
(1, $vocabularyId, 'Directory', 'directory', 'A database of information which is heavily optimized for reading.'),
(1, $vocabularyId, 'DisciplineDictionary', 'discipline dictionary', 'A discipline dictionary is a collection of subject disciplines.'),
(1, $vocabularyId, 'DoctoralThesis', 'doctoral thesis', 'A thesis reporting the research undertaken during a period of graduate study leading to a doctoral degree.'),
(1, $vocabularyId, 'DocumentRepository', 'document repository', 'A repository for storing documents.'),
(1, $vocabularyId, 'DustJacket', 'dust jacket', 'A detachable outer cover, usually made of paper and printed with text and illustrations. This outer cover has folded flaps that hold it to the cover of a document.'),
(1, $vocabularyId, 'Editorial', 'editorial', 'The realization of an opinion written by an editor.'),
(1, $vocabularyId, 'Email', 'e-mail', 'A message transmitted over the internet as an item of electronic mail, typically based on the Simple Mail Transfer Protocol (SMTP).  Emails can have computer files containing documents, dataset and images attached to them or embedded within them.'),
(1, $vocabularyId, 'EntityMetadata', 'entity metadata', 'Metadata describing the work itself, including for example the name of the creator(s), the title of the work, and the date and place of its creation.'),
(1, $vocabularyId, 'Entry', 'entry', 'An item written or printed in a diary, list, account book, reference book, or database.'),
(1, $vocabularyId, 'Erratum', 'erratum', 'A formal correction to an error introduced by the publisher into a previously published document.'),
(1, $vocabularyId, 'Essay', 'essay', 'A piece of non-fiction writing on a particular subject, usually of moderate length and without chapters.'),
(1, $vocabularyId, 'ExaminationPaper', 'examination paper', 'A set of questions on a particular topic designed to test the academic, professional or technical ability of the person taking the examination, with achievement of a pass grade in the examination typically being a prerequisite for the award of an educational award such as a degree, or of a professional or technical qualification.'),
(1, $vocabularyId, 'Excerpt', 'excerpt', 'A segment or passage selected from a larger expression for use in another expression, usually with specific attribution to its original source.\n\n[Note: Use fabio:Excerpt to indicate a segment or passage selected from another expression that is not a passage of speech, and fabio:Quotation to indicate a segment or passage selected from another expression that is a passage of speech.]'),
(1, $vocabularyId, 'ExecutiveSummary', 'executive summary', 'An executive summary is a brief report summarizing a longer formal report, designed to present the key points, conclusions and recommendations arising from the study being reported, for readers too busy to take the time to read the complete report.'),
(1, $vocabularyId, 'ExperimentalProtocol', 'experimental protocol', 'A predefined written procedural method, designed to ensure successful replication of results by others in the same or other laboratories, that describes the overall objectives, organization and implementation of a scientific experiment, and specifies the experimental design, experimental methods, reagents, instrumentation, sampling schedules, data collection parameters, statistical analyses, image processing procedures, safety precautions, reporting standards, etc. employed in undertaking the experiment.'),
(1, $vocabularyId, 'Expression', 'expression', 'A subclass of FRBR expression, restricted to expressions of fabio:Works.  For your latest research paper, the preprint submitted to the publisher, and the final published version to which the publisher assigned a unique digital object identifier, are both expressions of the same work.  '),
(1, $vocabularyId, 'ExpressionCollection', 'expression collection', 'A collection of expressions, for example a periodical or a book series.'),
(1, $vocabularyId, 'Figure', 'figure', 'A visual communication object comprising one or more still images on a related theme.  If included within a publication, a figure is typically unaligned with the main body of text, having its own descriptive textual figure legend.'),
(1, $vocabularyId, 'Film', 'film', 'A movie with an accompanying soundtrack, typically created by a professional film studio, designed to communicate a fictional story, record an artistic event, or impart information that is scientific or documentary in nature.'),
(1, $vocabularyId, 'Folksonomy', 'folksonomy', 'A system of classification derived from the practice and method of collaboratively creating and managing tags to annotate and categorize content in a particular domain. [Contrast fabio:Ontology]'),
(1, $vocabularyId, 'GanttChart', 'Gantt chart', 'A horizontal bar chart used to guide project planning, execution and control, illustrating the project schedule, with a separate line indicating the start and end dates of each of the key project activities or workpackages, and optionally showing the dependencies between these items. A Gantt chart is typically part of a project plan.'),
(1, $vocabularyId, 'GrantApplication', 'grant application', 'A formal written request for financial support from a grant-giving body in support of a project, for example an academic research project.  (See also fabio:CaseForSupport.)'),
(1, $vocabularyId, 'GrantApplicationDocument', 'grant application document', 'The realization of a grant application, usually containing a case for support document.'),
(1, $vocabularyId, 'Hardback', 'hardback', 'A print object bound with rigid protective covers (typically of cardboard covered with cloth, heavy paper, or sometimes leather).'),
(1, $vocabularyId, 'Image', 'image', 'A visual representation other than text, including all types of moving image and still image.'),
(1, $vocabularyId, 'InBrief', 'in brief', 'An \'In Brief\' is a journal or magazine news item that describes all the articles (or all the important articles) in that issue of the periodical. The content of an \'In Brief\' may be constructed from the abstracts of the articles it highlights, but is more likely to be written by a member of the periodical staff especially for the issue.'),
(1, $vocabularyId, 'InUsePaper', 'in-use paper', 'A scholarly work that describes applied and validated solutions such as software tools, systems or architectures that benefit from the use of the technology of a particular scholarly domain. Usually, papers of this kind should also provide convincing evidence that there is use of the proposed application or tool by the target user group, preferably outside the institution that conducted its development.\n\nE.g. see http://iswc2018.semanticweb.org/call-for-in-use-track-papers/.'),
(1, $vocabularyId, 'Index', 'index', 'An alphabetically-ordered list of words and phrases (\'headings\') and associated pointers (\'locators\') to where useful material relating to that heading can be found in a document.'),
(1, $vocabularyId, 'InstructionManual', 'instruction manual', 'An instructional document typically supplied with a technologically advanced consumer product, such as a car or a computer application, or with an item of complex equipment such as a microscope.'),
(1, $vocabularyId, 'InstructionalWork', 'instructional work', 'A work created for the purpose of education or instruction, that may be expressed as a  text book, a lecture, a tutorial or an instruction manual.'),
(1, $vocabularyId, 'Item', 'item', 'A subclass of FRBR item, restricted to exemplars of fabio:Manifestations.  An example of a fabio:Item is a printed copy of a journal article on your desk, or a PDF file of that article that you purchased from a publisher and that now resides in digital form on your computer hard drive.  '),
(1, $vocabularyId, 'ItemCollection', 'item collection', 'A collection of items.'),
(1, $vocabularyId, 'Journal', 'journal', 'A scholarly periodical primarily devoted to the publication of original research papers. [Printed and electronic manifestations of the same journal are usually identified by separate print and electronic International Standard Serial Numbers (ISSN or eISSN, respectively), that identifies the journal as a whole, not to individual issues of it.]'),
(1, $vocabularyId, 'JournalArticle', 'journal article', 'An article, typically the realization of a research paper reporting original research findings, published in a journal issue.  '),
(1, $vocabularyId, 'JournalEditorial', 'journal editorial', 'An editorial published in an issue of a journal.'),
(1, $vocabularyId, 'JournalIssue', 'journal issue', 'A particular published issue of a journal, one or more of which will constitute a volume of the journal.'),
(1, $vocabularyId, 'JournalNewsItem', 'journal news item', 'A news report published in a journal issue.'),
(1, $vocabularyId, 'JournalVolume', 'journal volume', 'A particular published volume of a journal, comprising one or more journal issues.'),
(1, $vocabularyId, 'LaboratoryNotebook', 'laboratory notebook', 'A notebook used by an individual research scientist as the primary record of his or her research activities. A researcher may use a laboratory notebook to document hypotheses, to describe experiments and to record data in various formats, to provide details of data analysis and interpretation, or to record the validation or invalidation of the original hypotheses. The laboratory notebook serves as an organizational tool and a memory aid.  It may also have a role in recording and protecting any intellectual property created during the research, and may be used in evidence when establishing priority of discoveries, for example in patent applications.  Electronic versions of laboratory notebooks are increasingly being employed by researchers, particularly in chemistry and the pharmaceutical industry.'),
(1, $vocabularyId, 'LectureNotes', 'lecture notes', 'A document containing notes that summarize a lecture or course of lectures.'),
(1, $vocabularyId, 'LegalOpinion', 'legal opinion', 'A written explanation by a judge or group of judges that accompanies a ruling in a legal case, laying out the reasons and legal principles for the ruling, and sometimes containing pronouncements about what the law is and how it should be interpreted.'),
(1, $vocabularyId, 'Letter', 'letter', 'A written or printed communication of a personal or professional nature between individuals and/or representatives of corporate bodies, usually transmitted by the postal service or published in a periodical.  In the latter case, the letter is typically addressed to the editor and comments on or discussed an item previously published by that periodical, or of interest to its readership.'),
(1, $vocabularyId, 'LibraryCatalog', 'library catalog', 'The catalog of the holdings of a library, for example that of the Library of Congress (http://catalog.loc.gov/).'),
(1, $vocabularyId, 'LiteraryArtisticWork', 'literary artistic work', 'A literary creative work, such as a novel, play, poem or song.'),
(1, $vocabularyId, 'Magazine', 'magazine', 'A periodical, usually devoted to a particular topic or domain of interest, and usually published weekly or monthly, consisting primarily of  non-peer reviewed editorials, journalistic news items and more substantive articles, reviews, book reviews and discussions concerning current or recent events and publications, and matters of interest to the domain served by the magazine.  [Some scientific journals, notably Science and Nature, also secondarily serve as science magazines by containing substantive editorials and news items on vital or controversial issues].'),
(1, $vocabularyId, 'MagazineArticle', 'magazine article', 'An article published in a magazine issue.'),
(1, $vocabularyId, 'MagazineEditorial', 'magazine editorial', 'An editorial published in an issue of a magazine.'),
(1, $vocabularyId, 'MagazineIssue', 'magazine issue', 'A particular published  issue of a magazine, identified by date, and sometimes also by place (e.g. \'West Coast edition\') or language (e.g. \'Spanish edition\').'),
(1, $vocabularyId, 'MagazineNewsItem', 'magazine news item', 'A news report published in a magazine issue.'),
(1, $vocabularyId, 'Manifestation', 'manifestation', 'A subclass of FRBR manifestation, restricted to manifestations of fabio:Expressions. fabio:Manifestation specifically applies to electronic (digital) as well as to physical manifestations of expressions.  \n\nExamples of different manifestations of a single \'version of record\' expression of a scholarly work include an article in a print journal or the on-line version of that article as a web page.'),
(1, $vocabularyId, 'ManifestationCollection', 'manifestation collection', 'A collection of manifestations.'),
(1, $vocabularyId, 'Manuscript', 'manuscript', 'A textual work prepared \'by hand\', such as a typescript or word-processed pre-publication draft of a research paper or a report, or a work not otherwise reproduced in multiple copies.  [Note: fabio:Manuscript is not intended to describe a handwritten historical document on paper or parchment, for which the FRBR distinctions between work, expression, manifestation and item (individual copy) becomes blurred.].'),
(1, $vocabularyId, 'MastersThesis', 'master\'s thesis', 'A thesis reporting a research project undertaken as part of a graduate course of education leading to a master\'s degree.'),
(1, $vocabularyId, 'MeetingReport', 'meeting report', 'A report of a meeting of some kind.'),
(1, $vocabularyId, 'Metadata', 'metadata', 'A separate work that provides information describing one or more characteristics of a resource or entity.'),
(1, $vocabularyId, 'MetadataDocument', 'metadata document', 'A document that contains metadata information describing one or more characteristics of an entity.'),
(1, $vocabularyId, 'MethodsPaper', 'methods paper', 'A scholarly work detailing a method, procedure or experimental protocol employed in a particular scholarly domain.'),
(1, $vocabularyId, 'Microblog', 'microblog', 'A social networking publication medium such as Twitter, Tumblr, FriendFeed, Facebook or MySpace. A microblog differs from a traditional blog in that its individual content items are smaller than a traditional blog posts, typically containing just a short sentence, a single image, or a URI.  These small messages are referred to as microposts.'),
(1, $vocabularyId, 'Micropost', 'micropost', 'A content item that is published in a Microblog, typically containing just a short sentence, a single image, or a URL.'),
(1, $vocabularyId, 'MinimalInformationStandard', 'minimal information standard', 'A metadata standard specifying items to be included when creating metadata describing a dataset of a particular type, or when creating a structured summary of the main findings of an article or report in a particular domain of interest, thereby ensuring adequate descriptive information is recorded for subsequent resource discovery and/or interpretation of the information described.  [See also fabio:ReportingStandard.]'),
(1, $vocabularyId, 'Model', 'model', 'A mathematical, graphical or physical representation of some physical reality, conceptual idea or theoretical construct.'),
(1, $vocabularyId, 'Movie', 'movie', 'The realization of a moving image.'),
(1, $vocabularyId, 'MovingImage', 'moving image', 'A moving display, either generated dynamically by a computer program or formed from a series of pre-recorded still images imparting an impression of motion when shown in succession.  Examples include animations, cine films, videos, and computational simulations. Expressions of moving images may incorporate synchronized soundtracks.'),
(1, $vocabularyId, 'MusicalComposition', 'musical composition', 'A piece of music, typically in the form of a composition recorded in musical notation.'),
(1, $vocabularyId, 'Nanopublication', 'nanopublication', 'A single, attributable and machine-readable factual assertion - the smallest unit of publishable information that can be uniquely identified and attributed to its author – typically expressed in RDF.  The minimal components of a nanopublication are as follows:\n* the factual assertion itself, in the form subject, predicate and object (e.g. malaria is_a disease);\n* provenance information about the nanopublication, defining its authorship and creation date;\n* supporting information (optional), providing context for the assertion;\n* a unique identifier for the nanopublication, in the form of a URI;\n* an integrity key that ensures that the nanopublication is in its original form and has not been altered.\n'),
(1, $vocabularyId, 'NewsItem', 'news item', 'A published news report.'),
(1, $vocabularyId, 'NewsReport', 'news report', 'A report of an item of news.'),
(1, $vocabularyId, 'Newspaper', 'newspaper', 'A non-peer reviewed periodical, usually published daily or weekly, consisting primarily of editorials and news items concerning current or recent events and matters of public interest.'),
(1, $vocabularyId, 'NewspaperArticle', 'newspaper article', 'An article written by a journalist and published in a newspaper.'),
(1, $vocabularyId, 'NewspaperEditorial', 'newspaper editorial', 'An editorial published in an issue of a newspaper.'),
(1, $vocabularyId, 'NewspaperIssue', 'newspaper issue', 'A particular published  issue of a newspaper, identified by date, and sometimes also by place or time (e.g. \'Late London Edition\').'),
(1, $vocabularyId, 'NewspaperNewsItem', 'newspaper news item', 'A news report published in a newspaper issue.'),
(1, $vocabularyId, 'Notebook', 'notebook', 'A book containing personal notes, typically created by writing into a physical book with blank pages.'),
(1, $vocabularyId, 'NotificationOfReceipt', 'notification of receipt', 'A notification of receipt of something, for example of receipt of a book that will later be the subject of a book review.'),
(1, $vocabularyId, 'Novel', 'novel', 'A long fictitious narrative written in literary prose.'),
(1, $vocabularyId, 'Obituary', 'obituary', 'A news item reporting the death of a person, typically accompanied by an description of that person\'s life and contributions to his or her profession and to society at large.'),
(1, $vocabularyId, 'Ontology', 'ontology', 'A formal representation of a set of concepts within a domain of knowledge, and the logical relationships between those concepts.  [Contrast fabio:Folksonomy]'),
(1, $vocabularyId, 'OntologyDocument', 'ontology document', 'A document containing an ontology, for example an OWL (Web Ontology Language) file (http://www.w3.org/TR/owl-features/).'),
(1, $vocabularyId, 'Opinion', 'opinion', 'An expression of a personal or professional opinion on an issue or topic.'),
(1, $vocabularyId, 'Oration', 'oration', 'A formal speech, for example one delivered at a ceremonial occasion, or the written transcript of such a speech.'),
(1, $vocabularyId, 'Page', 'page', 'A manifestation that represents pages either in physical (e.g., one side of a sheet of paper) or in digital form (e.g., a page in a PDF, or a web page).'),
(1, $vocabularyId, 'Paperback', 'paperback', 'A print object with a flexible cover, usually made of paper or paperboard.'),
(1, $vocabularyId, 'Patent', 'patent', 'A formal disclosure of a new invention approved by a governmental patent agency, made to register intellectual property rights, and to give exclusive rights to the inventor or assignee to manufacture, use, license or sell the invention for a certain number of years.'),
(1, $vocabularyId, 'PatentApplication', 'patent application', 'A formal disclosure of a new invention, made in application for a patent.'),
(1, $vocabularyId, 'PatentApplicationDocument', 'patent application document', 'The physical or electronic realization of a patent application.'),
(1, $vocabularyId, 'PatentDocument', 'patent document', 'The physical or electronic realization of a patent.'),
(1, $vocabularyId, 'Periodical', 'periodical', 'A publication issued on a regular and ongoing basis as a series of issues, each issue comprising separate periodical items, for example editorials, articles, news items and/or other writings.'),
(1, $vocabularyId, 'PeriodicalIssue', 'periodical issue', 'A particular issue of a periodical, identified and distinguished from other issues of the same publication by date and/or issue number and/or volume number, and comprising separate periodical items such as editorials, articles and news items.'),
(1, $vocabularyId, 'PeriodicalItem', 'periodical item', 'A piece of writing published in a periodical issue, typically accompanied by other items by different authors.'),
(1, $vocabularyId, 'PeriodicalVolume', 'periodical volume', 'A particular published volume of a periodical.'),
(1, $vocabularyId, 'PersonalCommunication', 'personal communication', 'Information communicated personally by verbal or written means from one individual to one or more another persons or organizations.'),
(1, $vocabularyId, 'PhDSymposiumPaper', 'Ph.D. symposium paper', 'A paper, usually presented during a specific session of a conference dedicated to Ph.D. students, that describes ongoing Ph.D. student\'s research.'),
(1, $vocabularyId, 'Play', 'play', 'A form of literature written by a playwright, usually consisting of scripted dialogue between characters, intended for theatrical performance rather than reading.'),
(1, $vocabularyId, 'Poem', 'poem', 'An artistic work written with an intensity or beauty of language more characteristic of poetry than of prose.'),
(1, $vocabularyId, 'Policy', 'policy', 'A description and definition of how something should be done.  Ideally a policy should be both effective in achieving its goals and acceptable to those who have to abide by it.'),
(1, $vocabularyId, 'PolicyDocument', 'policy document', 'A document embodying a policy that descibes and defines how something should be done. '),
(1, $vocabularyId, 'PositionPaper', 'position paper', 'A scholarly work that reports a particular intellectual position or viewpoint regarding a particular scholarly topic. Usually, these papers are dependent on the author\'s opinion or interpretation, do not have an evaluation, and need to present relevant and novel discussion points in a thorough manner.\n\nE.g. see https://datasciencehub.net/content/guidelines-authors'),
(1, $vocabularyId, 'PosterPaper', 'poster paper', 'A paper that typically accompanies a poster describing some preliminary  results of research, usually presented during a conference or a workshop.'),
(1, $vocabularyId, 'Postprint', 'postprint', 'The version of an author\'s original scholarly work, such as a research paper or a review, re-submitted for publication after revision by the author in the light of comments from reviewers.  [Note: For the version before peer review, use fabio:Preprint. For the final piblished version, use fabio:DefinitiveVersion.]'),
(1, $vocabularyId, 'Preprint', 'preprint', 'The version of an author\'s original scholarly work, such as a research paper or a review, first submitted to publisher for publication.  [Note: For that version resubmitted after peer-review and revision, use fabio:Postprint. For the final published version use fabio:DefinitiveVersion.]'),
(1, $vocabularyId, 'Presentation', 'presentation', 'A set of slides containing text, tables or figures, designed to communicate ideas or research results, for projection and viewing by an audience at a conference, symposium, seminar, lecture, workshop or other gatherings, typically embodied in a particular manifestation format such as a SlideShare or PowerPoint slideshow.'),
(1, $vocabularyId, 'PressRelease', 'press release', 'A news report published by an organization to provide information to journalists.'),
(1, $vocabularyId, 'PrintObject', 'print object', 'An analog manifestation in physical printed form, typically on paper.'),
(1, $vocabularyId, 'ProceedingsPaper', 'proceedings paper', 'A paper, typically the realization of a research paper reporting original research findings, usually published within an academic proceedings volume.'),
(1, $vocabularyId, 'ProductReview', 'product review', 'A written review and critical analysis of the purpose, features, performance and other qualities of a product.'),
(1, $vocabularyId, 'ProjectMetadata', 'project metadata', 'Metadata describing a project, for example the project name, the names of those who conducted the project, the name of the institution in which the project was conducted, and the project funding information.'),
(1, $vocabularyId, 'ProjectPlan', 'project plan', 'A document used to guide project planning, execution and control, specifying the project\'s goal and objectives and the activities and resources required to achieve these, setting out the project schedule, and identifying the major workpackages, milestones and deliverables.  A project plan will typically contain a Gantt chart.\n'),
(1, $vocabularyId, 'ProjectReport', 'deliverable report', 'A report describing the outcomes of specific project, typically listing \'deliverables\' created or \'milestones\' achieved during the project.'),
(1, $vocabularyId, 'ProjectReportDocument', 'deliverable', 'A document containing a project report, intended to be delivered to a customer or funding agency describing the results achieved within a specific project. '),
(1, $vocabularyId, 'Proof', 'proof', 'In printing and publishing, a proof copy is the preliminary version of a publication, after the inclusion of any author corrections following review, and after copy editing and formatting to bring the manuscript into the house style, intended for final checking prior to publication to detect and eliminate typographical errors, omissions or transpositions of text, incorrect layout or placement of illustrations and tables, or other formatting errors.  Those who check proofs include the editor, possibly the peer-reviewers (to ensure that their requested modifications have been included to their satisfaction), possibly an in-house professional proof-reader, and / or the author, who is ultimately responsible for ensuring the published work says what (s)he means it to say.  Substantive changes to the text are not permitted once the manuscript has reached proof stage.'),
(1, $vocabularyId, 'Proposition', 'proposition', 'A proposal or proposition of a new conceptualization, hypothesis, idea, theory, activity or organisation.'),
(1, $vocabularyId, 'Questionnaire', 'questionnaire', 'A set of questions on a particular topic, usually in the form of multiple choice questions requiring the respondent to select the correct answer, or providing the ability to indicate support for or against a proposal on a numerical scale, designed for rapid numerical analysis of responses and often used in surveying public opinion.'),
(1, $vocabularyId, 'Quotation', 'quotation', 'A passage of speech selected from a larger verbal or written expression for use in another expression, with specific attribution to its original source, and usually demarcated by quotation marks and / or by placing it in a separate indented paragraph. \n\n[Note: Use fabio:Quotation to indicate a segment or passage selected from another expression that is a passage of speech, and fabio:Excerpt to indicate a segment or passage selected from another expression that is not a passage of speech.]'),
(1, $vocabularyId, 'RapidCommunication', 'rapid communication', 'A short rapidly published research article or conference paper, typically reporting significant research results that have been recently discovered, or a brief news item reporting such discoveries.'),
(1, $vocabularyId, 'ReferenceBook', 'reference book', 'A book containing authoritative factual information, such as a dictionary, encyclopaedia, handbook or field guide, which is a realisation of a certain reference work and may contain several reference entries.'),
(1, $vocabularyId, 'ReferenceEntry', 'reference entry', 'A particular reference entry containing authoritative factual information on a certain topic, usually contained in a larger expression.'),
(1, $vocabularyId, 'ReferenceWork', 'reference work', 'A work to which people refer for authoritative factual information, such as a dictionary, encyclopaedia, entry, handbook or field guide, or an informative web page such as an institutional, research group or project home page.'),
(1, $vocabularyId, 'RelationalDatabase', 'relational database', 'A database in which the data are arranged in tables according to their common characteristics, with relationships between the tables being defined by a relational model or schema. A relational database is highly optimized for performance, and is queried using a database query language such as SQL (Structured Query Language).  The software used to create a relational database is called a relational database management system (RDBMS).'),
(1, $vocabularyId, 'Reply', 'reply', 'A work that is a reply, either to a letter or other direct communication, or to feedback or comments about a piece of submitted writing.  The latter is typically written by the author of a journal article submitted for publication, or by an applicant making a grant application, in response to reviews of the work from peer reviewers prior to publication (for the journal article) or prior to funding decision (for the grant application).  Alternatively, it can be written in response to post-publication peer-review of a published journal article, or comments about it.'),
(1, $vocabularyId, 'Report', 'report', 'A formal factual, methodological, statistical, technical or research report issued by an individual, group, agency, government body or other institution.'),
(1, $vocabularyId, 'ReportDocument', 'report document', 'The realization of a report, usually in printed form.'),
(1, $vocabularyId, 'ReportingStandard', 'reporting standard', 'A set of recommendations for the minimum reporting requirements to be employed when reporting a particular type of investigation or project, for example a randomized clinical trial.  A reporting standard may involve a checklist and a flow diagram, offers a standard way for authors to prepare a complete and transparent report of their findings, and aids their critical appraisal and interpretation of their data. [See also fabio:MinimalInformationStandard.]'),
(1, $vocabularyId, 'Repository', 'repository', 'A computer system in which information may be stored.'),
(1, $vocabularyId, 'ResearchPaper', 'research paper', 'A scholarly work that reports original research contributions addressing theoretical, analytical or experimental aspects of a particular scholarly domain.\n\nE.g. see http://iswc2018.semanticweb.org/call-for-research-track-papers/.'),
(1, $vocabularyId, 'ResourcePaper', 'resource paper', 'A scholarly work that describes resources developed to provide experimental materials or facilities, support a research hypothesis, to provide answers to a research question, or that have contributed to the generation of novel scientific work. Examples of such resources include, for experimental sciences, mouse mutant lines and large communally used X-ray or neutron sources, and, for computer sciences, datasets, ontologies, vocabularies, ontology design patterns, evaluation benchmarks or methods, services, APIs and software frameworks, workflows, crowdsourcing task designs, protocols and metrics.\n\nE.g. see http://iswc2018.semanticweb.org/call-for-resources-track-papers/'),
(1, $vocabularyId, 'Retraction', 'retraction', 'A formal statement retracting a statement or publication\nA retraction is a public statement made about an earlier statement that withdraws, cancels, refutes, diametrically reverses the original statement or ceases and desists from publishing the original statement. '),
(1, $vocabularyId, 'Review', 'review', 'A review of others\' work.'),
(1, $vocabularyId, 'ReviewArticle', 'review article', 'An article that contains a review.'),
(1, $vocabularyId, 'ReviewPaper', 'review paper', 'A scholarly work that surveys the state of the art of topics central to a particular subject or relating to a specific domain (e.g. the scope of a certain journal or conference). Papers of this kind may contain a selective bibliography listing key papers related to the subject or providing advice on information sources, or they may strive to be comprehensive, covering all contributions to the development of a topic and exploring their different findings or views.\n\nE.g. see http://www.emeraldgrouppublishing.com/products/journals/author_guidelines.htm?id=JD'),
(1, $vocabularyId, 'ScholarlyWork', 'scholarly work', 'A work that reports scholarly activity on a particular topic, either published in written form, or delivered orally at a meeting.'),
(1, $vocabularyId, 'Screenplay', 'screenplay', 'A written work made especially for a film or television program. Screenplays can be original works or adaptations from existing pieces of writing, for example novels. '),
(1, $vocabularyId, 'Script', 'script', 'A small computer program written in a scripting language such as JavaScript, PHP or Perl that allows control of one or more software applications.'),
(1, $vocabularyId, 'Series', 'series', 'A sequence of expressions having certain characteristics in common that are formally identified together as a group.'),
(1, $vocabularyId, 'ShortStory', 'short story', 'A work of fiction that is usually written in prose, often in narrative format. This format tends to be more focused and less elaborate than longer works of fiction such as novels.'),
(1, $vocabularyId, 'Song', 'song', 'A musical composition that contains vocal parts (\'lyrics\') that are performed (\'sung\').'),
(1, $vocabularyId, 'SoundRecording', 'sound recording', 'The creative work of making an electrical or mechanical recording of sounds, such as the spoken voice, singing, instrumental music, animal vocalizations or sound effects. '),
(1, $vocabularyId, 'Specification', 'specification', 'An explicit description of, or set of requirements to be satisfied by, a material, product, resource, service or standard.'),
(1, $vocabularyId, 'SpecificationDocument', 'specification document', 'The realization of a specification (a standard, a workflow, etc.).'),
(1, $vocabularyId, 'Spreadsheet', 'spreadsheet', 'An electronic form of data storage that displays a grid of rows and columns, in which each editable cell can contain alphanumeric text, a numeric value, or a formula that defines how the content of that cell is to be calculated from the content of another cell or cells.'),
(1, $vocabularyId, 'StandardOperatingProcedure', 'standard operating procedure', 'Clear and detailed written instructions of a prescribed step-by-step procedure to be routinely followed, and decisions to be made when undertaking a specific task, process or function, to achieve consistent performance, ensure safety and/or assure data quality.  (Commonly abbreviated \'SOP\'.)'),
(1, $vocabularyId, 'StillImage', 'still image', 'A recorded static visual representation. This class of image includes diagrams, drawings, graphs, graphic designs, plans, maps, photographs and prints.'),
(1, $vocabularyId, 'StorageMedium', 'storage medium', 'A device for recording information or storing data.'),
(1, $vocabularyId, 'StructuredSummary', 'structured summary', 'A structured summary containing essential metadata describing a research investigation and/or the research outputs that have resulted from it, for example datasets and journal articles, structured according to some minimal information standard.  Such a structured summary can be embodied in both human-readable and machine-readable manifestations, e.g. HTML and RDF.  Such a structured summary differs from the Abstract of a journal article, in that the latter is written as a piece of continuous prose, but typically omits vital factual information about the investigation, such as when and where it was conducted, by whom, and on now many specimens or subjects.'),
(1, $vocabularyId, 'SubjectDiscipline', 'subject discipline', 'A concept that identifies a field of knowledge or human activity defined in a controlled vocabulary, such as Computer Science, Biology, Economics, Cookery or Swimming.'),
(1, $vocabularyId, 'SubjectTerm', 'subject term', 'A concept that defines a term within the controlled vocabulary of a particular classification system, such as the ACM Computing Classification System or MeSH, the Medical Subject Headings, used as an annotation to describe the subject, meaning or content of an entity.'),
(1, $vocabularyId, 'Supplement', 'supplement', 'A supplement to a publication such as a book, journal, magazine or newspaper, additional to the main publication.  For example, a colour supplement to a sunday newspaper, or a special supplementary issue of a journal or a journal volume containing invited articles on a special topic, or abstracts or papers presented at a scientific conference.'),
(1, $vocabularyId, 'SupplementaryInformation', 'supplementary information file', 'A file accompanying a published journal article, containing additional information of relevance to the article, typically available from the publisher\'s web site via a hyperlink from the journal article itself.'),
(1, $vocabularyId, 'SystematicReview', 'systematic review', 'A literature review focused on a single question that tries to identify, appraise, select and synthesize all high quality research evidence relevant to that question. Systematic reviews of high-quality randomized controlled trials are crucial to evidence-based medicine. An understanding of systematic reviews and how to implement them in practice is becoming mandatory for all professionals involved in the delivery of health care. Systematic reviews are not limited to medicine,  and are quite common in other sciences such as psychology, educational research and sociology.'),
(1, $vocabularyId, 'Table', 'table', 'A graphical means of presenting data in a grid of rows and columns, within which the cells usually contain alphanumeric text or numeric values.  If included within a publication, a table typically appearing unaligned with the main body of text, with its own descriptive title.'),
(1, $vocabularyId, 'TableOfContents', 'table of contents', 'A table listing the parts of publication such as a book or technical specification, and the pages on which these content elements start (if the publication is printed or otherwise organized into pages), usually listed in order of appearance.  The Table of Contents typically includes first-level headers, such as the chapter titles of a book, and may also include second- and even third-level headers.  In electronic works, the Table of Contents entries are often internally hyperlinked to the content items, so that clicking on the entry takes the reader to that item.'),
(1, $vocabularyId, 'Taxonomy', 'taxonomy', 'A classification arranged in a hierarchical structure of classes and subclasses, showing parent-child isA relationships, or broader_than - narrower_than relationships.'),
(1, $vocabularyId, 'TechnicalReport', 'technical report', 'A report of a technical nature.'),
(1, $vocabularyId, 'TechnicalStandard', 'technical standard', 'An official or public specification of, or requirement for, a technical method, practice, process or protocol that is involved in, for example, manufacturing, computation, electronic communication, or digital media.'),
(1, $vocabularyId, 'TermDictionary', 'term dictionary', 'A controlled vocabulary, usually referring to terms within a particular classification system, such as the ACM Computing Classification System or MeSH, the Medical Subject Headings, or a controlled vocabulary of disciplines.'),
(1, $vocabularyId, 'Textbook', 'textbook', 'A book containing instructional material relating to a particular topic of academic study, designed to be read by students.'),
(1, $vocabularyId, 'Thesaurus', 'thesaurus', 'A type of controlled vocabulary used in information retrieval applications for indexing or tagging purposes, in which relationships between terms are made explicit. These are normally hierarchical relationships (is-a, subsumption; e.g. a cow is a mammal), equivalency relationships relating non-preferred terms to preferred terms (e.g. pitch and frequency), or associative relationships, in which the relationship that exists is neither one of hierarchy or equivalence, but rather one of similarity (e.g. sports and leisure pursuits).'),
(1, $vocabularyId, 'Thesis', 'thesis', 'A book authored by a student containing a formal presentations of research outputs submitted for examination in completion of a course of study at an institution of higher education, to fulfil the requirements for an academic degree.  Also know as a dissertation.  [For the alternative meaning of the word \'thesis\', namely the formulation of a concept, hypothesis, idea, point of view or theory presented for review and/or discussion, use fabio:Proposition.]'),
(1, $vocabularyId, 'Timetable', 'timetable', 'A tabular dataset providing information about the times and locations of a planned series of events.'),
(1, $vocabularyId, 'TrialReport', 'trial report', 'The report of a trial, for example an experimental trial or a legal trial.'),
(1, $vocabularyId, 'Triplestore', 'triplestore', 'A database specifically designed for the storage and retrieval of Resource Description Framework (RDF) data consisting of subject-predicate-object triples.  A triple store is queried using the RDF query language SPARQL.'),
(1, $vocabularyId, 'Tweet', 'tweet', 'A posting made on the social networking site Twitter. A tweet is a text message limited to 140 characters in length, that is broadcast and readable by anyone who accesses Twitter.'),
(1, $vocabularyId, 'UncontrolledVocabulary', 'uncontrolled vocabulary', 'A non-defined collection of words and phrases relating to a particular domain of knowledge, usually added freely by a community, in which homonyms, synonyms and similar ambiguities of meaning present in natural language are not formally disambiguated.'),
(1, $vocabularyId, 'Vocabulary', 'vocabulary', 'A set of words, either constituting a language, or more specifically used to describe a particular domain of knowledge.'),
(1, $vocabularyId, 'VocabularyDocument', 'vocabulary document', 'A document containing a vocabulary'),
(1, $vocabularyId, 'VocabularyMapping', 'vocabulary mapping', 'A mapping of correspondences between two vocabularies.  For controlled vocabularies, such mappings may be expressed using SKOS (http://www.w3.org/2004/02/skos/).'),
(1, $vocabularyId, 'VocabularyMappingDocument', 'vocabulary mapping document', 'A document containing a vocabulary mapping'),
(1, $vocabularyId, 'WebArchive', 'web archive', 'A snapshots of (part of) the World Wide Web.'),
(1, $vocabularyId, 'WebContent', 'web content', 'Information prepared specifically and primarily for manifestation in a web page, comprising text, images, datasets and/or other works.'),
(1, $vocabularyId, 'WebManifestation', 'web manifestation', 'A digital manifestation on the Web, such as a wiki, a web site, a web page or a blog.'),
(1, $vocabularyId, 'WebPage', 'web page', 'A Web manifestation usually identified by a Uniform Resource Identifier (URI), and made accessible to a user by means of the Hypertext Transport Protocol (HTTP) in a Web browser window. Several interlinked web pages hosted together on a Web server and accessed through a single domain name or IP address constitute a web site.'),
(1, $vocabularyId, 'WebSite', 'web site', 'A collection of related web pages containing text, images, videos and/or other digital assets that are addressed relative to a common Uniform Resource Locator (URL). A web site is hosted on at least one web server, accessible via a network such as the Internet or a private local area network.'),
(1, $vocabularyId, 'WhitePaper', 'white paper', 'An authoritative report or guide designed to educate readers and help people make decisions, or to explain technical problems and how to solve them. White papers are typically published by governments to propose new legislation for discussion, and by commercial companies to inform readers about products or services, as aids to marketing.'),
(1, $vocabularyId, 'Wiki', 'wiki', 'A collaborative Web manifestation, usually maintained by a project team or group, providing easy-to-edit pages that can be used to accumulate related information for shared use by the group and/or publication.'),
(1, $vocabularyId, 'WikiEntry', 'wiki entry', 'Information manifested in a wiki. '),
(1, $vocabularyId, 'WikipediaEntry', 'wikipedia entry', 'Information about a particular topic in one of the versions of Wikipedia, the online encyclopedia (http://www.wikipedia.org/).\n'),
(1, $vocabularyId, 'Work', 'work', 'A subclass of FRBR work, restricted to works that are published or potentially publishable, and that contain or are referred to by bibliographic references, or entities used to define bibliographic references. FaBiO works, and their expressions and manifestations, are primarily textual publications such as books, magazines, newspapers and journals, and items of their content.  However, they also include datasets, computer algorithms, experimental protocols, formal specifications and vocabularies, legal records, governmental papers, technical and commercial reports and similar publications, and also bibliographies, reference lists, library catalogues and similar collections. For this reason, fabio:Work is not an equivalent class to frbr:ScholarlyWork.  An example of a fabio:Work is your latest research paper.'),
(1, $vocabularyId, 'WorkCollection', 'work collection', NULL),
(1, $vocabularyId, 'WorkPackage', 'work package', 'A component of the case for support of a grant application, describing a particular aspect of the work to be undertaken.'),
(1, $vocabularyId, 'Workflow', 'workflow', 'A recorded sequence of connected steps, which may be automated, specifying a reliably repeatable sequence of operations to be undertaken when conducting a particular job, for example an in silico investigation that extracts and processes information from a number of bioinformatics databases.'),
(1, $vocabularyId, 'WorkingPaper', 'working paper', 'An unpublished paper, usually circulated privately among a small group of peers, to provide information or with a request for comments or editorial improvement.'),
(1, $vocabularyId, 'WorkshopPaper', 'workshop paper', 'A paper, typically the realization of a research paper reporting original research findings, usually presented at a workshop and published within a workshop proceedings volume.'),
(1, $vocabularyId, 'WorkshopProceedings', 'workshop proceedings', 'A document containing the programme and collected workshop papers, or their abstracts, presented at a workshop or similar event.');

INSERT INTO `property` (`owner_id`, `vocabulary_id`, `local_name`, `label`, `comment`) VALUES
(1, $vocabularyId, 'dateLastUpdated', 'date last updated', 'The date on which a particular endeavour, such as an ontology, was last updated.'),
(1, $vocabularyId, 'hasAccessDate', 'has access date', 'The date on which a particular digital item, such as a PDF or an HTML file, has been accessed by somebody.'),
(1, $vocabularyId, 'hasArXivId', 'has ArXiv identifier', 'An identifier used by the preprint repository ArXiv.'),
(1, $vocabularyId, 'hasCODEN', 'has CODEN', 'A CODEN is a six character, alphanumeric bibliographic identification code, that provides concise, unique and unambiguous identification of the titles of serials and non-serial publications.'),
(1, $vocabularyId, 'hasCharacterCount', 'has character count', 'The count of the number of characters in a textual resource.'),
(1, $vocabularyId, 'hasCopyrightYear', 'has copyright year', 'The year in which an entity has been copyrighted.'),
(1, $vocabularyId, 'hasCorrectionDate', 'has correction date', 'The date on which something, for example a document, is corrected.'),
(1, $vocabularyId, 'hasDateCollected', 'has date collected', 'The date on which some item has been collected, for example the data gathered by means of questionnaires.'),
(1, $vocabularyId, 'hasDateReceived', 'has date received', 'The date on which some item is received, for example a document being received by a publisher.'),
(1, $vocabularyId, 'hasDeadline', 'has deadline', 'A date by which something has to be done.'),
(1, $vocabularyId, 'hasDecisionDate', 'has decision date', 'The date on which a particular endeavour, such as a grant application, has been or will be approved or rejected by somebody.'),
(1, $vocabularyId, 'hasDepositDate', 'has deposit date', 'The date on which an entity has been deposited, for example in a library, repository, supplementary information archive, database or similar place of document or information storage.'),
(1, $vocabularyId, 'hasDiscipline', 'has discipline', 'The discipline to which a subject vocabulary belongs.'),
(1, $vocabularyId, 'hasDistributionDate', 'has preprint dissemination date', 'The date on which something is distributed, for example the date on which a preprint of a document is e-mailed to colleagues and other academics by the author(s), or the date on which a printed announcement of forthcoming theatre events is mailed to those those on the theatre\'s mailing list.'),
(1, $vocabularyId, 'hasElectronicArticleIdentifier', 'has electronic article identifier', 'A local identifier for an article within an electronic (i.e. on line, in HTML format) periodical issue.  Use in preference to prism:startingPage when the article lacks page numbers'),
(1, $vocabularyId, 'hasEmbargoDate', 'has embargo date', 'The date before which an entity should not be published, or before which a press release should not be reported on.  For open-access journal articles, the embargo date is the date before which availability of the open-access version of the article is restricted by the publisher, following subscription-access availability of the published work.  The duration of the embargo period can be specified by fabio:hasEmbargoDuration.'),
(1, $vocabularyId, 'hasEmbargoDuration', 'has embargo period', 'The time period for which an entity is embargoed.  During this period, the entity should not be published or, in the case of a press release, should not be reported on.  For open-access journal articles, the embargo duration specifies that period of time during which availability of the open-access version of the article is delayed by the publisher, following subscription-access availability of the published work.  The end of the embargo period can be specified by fabio:hasEmbargoDate.'),
(1, $vocabularyId, 'hasHandle', 'has handle', 'A persistent identifier of the Handel system for digital objects and other resources on the Internet.'),
(1, $vocabularyId, 'hasIssnL', 'has ISSN-L', 'A linking International Standard Serial Number.'),
(1, $vocabularyId, 'hasManifestation', 'has manifestation', 'A property linking a particular work to its manifestations.  This property is additional to the relationships between FRBR endeavours present in the classical FRBR data model.'),
(1, $vocabularyId, 'hasNLMJournalTitleAbbreviation', 'has National Library of Medicine journal title abbreviation', 'An internal identifier for the abbreviation of the title of journals available from the National Library of Medicine repository.'),
(1, $vocabularyId, 'hasNationalLibraryOfMedicineJournalId', 'National Library of Medicine journal identifier', 'An internal identifier for journals available from the National Library of Medicine repository.'),
(1, $vocabularyId, 'hasPII', 'has PII', 'Has Publisher Item Identifier'),
(1, $vocabularyId, 'hasPageCount', 'has page count', 'The count of the number of pages in a textual resource.'),
(1, $vocabularyId, 'hasPatentNumber', 'has patent number', 'A unique identifing number issued by a patent authority to identify a patent, displayed at the beginning of the patent document.'),
(1, $vocabularyId, 'hasPlaceOfPublication', 'has place of publication', 'The place (usually, the city) where the publisher of a particular bibliographic resource is located.'),
(1, $vocabularyId, 'hasPortrayal', 'has portrayal', 'A property linking a particular work to its items.  This property is additional to the relationships between FRBR endeavours present in the classical FRBR data model.'),
(1, $vocabularyId, 'hasPrimarySubjectTerm', 'has primary subject term', 'This property is used to associate a frbr:Endeavour to a term in a particular classification system - and the term is considered one of the main topics for the endeavour in consideration.'),
(1, $vocabularyId, 'hasPubMedCentralId', 'has PubMed Central identifier', 'An identifier for bibliographic entities hosted by the PubMed Central repository.'),
(1, $vocabularyId, 'hasPubMedId', 'has PubMed identifier', 'An identifier for bibliographic records held by the PubMed repository.'),
(1, $vocabularyId, 'hasPublicationYear', 'has publication year', 'The year in which a resource is published.'),
(1, $vocabularyId, 'hasRepresentation', 'has representation', 'A property linking a particular expression to its items.  This property is additional to the relationships between FRBR endeavours present in the classical FRBR data model.'),
(1, $vocabularyId, 'hasRequestDate', 'has request date', 'The date on which an agent is requested to do something, for example a reviewer is requested to write a review of a paper submitted to a journal for publication, or an author is requested to supply a revised version of the paper in response to the reviews received.'),
(1, $vocabularyId, 'hasRetractionDate', 'has retraction date', 'The date on which something, for example a claim or a journal article, is retracted.'),
(1, $vocabularyId, 'hasSICI', 'has SICI', 'The Serial Item and Contribution Identifier is a code used to uniquely identify specific volumes, articles or other identifiable parts of a periodical. It is intended primarily for use by those members of the bibliographic community involved in the use or management of serial titles and their contributions.'),
(1, $vocabularyId, 'hasSeason', 'has season', 'Permits specification of the season of the year, for example spring, summer, autumn and winter in British English.'),
(1, $vocabularyId, 'hasSequenceIdentifier', 'has number', 'A literal (for example a number or a letter) that identifies the sequence position of a work within a particular context, for example a book in a book series, a chapter in a document, a volume in a journal.'),
(1, $vocabularyId, 'hasShortTitle', 'has short title', 'A short version of the title of an entity, typically used to label or refer to a particular entity in an abbreviated form, for example an abbreviated journal title in a reference, or a short title of a document used as the running title in a page header.'),
(1, $vocabularyId, 'hasStandardNumber', 'has standard number', 'The number defining an international standard, for example Z39.96 - 201x, identifying NISO JATS, the Journal Article Tag Suite.'),
(1, $vocabularyId, 'hasSubjectTerm', 'has subject term', 'This property is used to associate a frbr:Endeavour to a term in a particular classification system.'),
(1, $vocabularyId, 'hasSubtitle', 'has subtitle', 'A secondary title that follows the main title of a work.'),
(1, $vocabularyId, 'hasTranslatedSubtitle', 'has translated subtitle', 'A version of the subtitle of an entity translated into another language, which may be specified using the object property dcterms:language.'),
(1, $vocabularyId, 'hasTranslatedTitle', 'has translated title', 'A version of the title of an entity translated into another language, which may be specified using the object property dcterms:language.'),
(1, $vocabularyId, 'hasURL', 'has URL', 'An identifier, in form of an HTTP Universal Resource Locator (URL), for a particular resource on the World Wide Web.'),
(1, $vocabularyId, 'hasVolumeCount', 'has volume count', 'The count of the number of volumes a work includes.'),
(1, $vocabularyId, 'isDisciplineOf', 'is discipline of', 'This property relates a subject vocabulary to the discipline to which it belongs.'),
(1, $vocabularyId, 'isManifestationOf', 'is manifestation of', 'A property linking a particular manifestation to the work it is manifesting.  This property is additional to the relationships between FRBR endeavours present in the classical FRBR data model.'),
(1, $vocabularyId, 'isPortrayalOf', 'is portrayal of', 'A property linking a particular item to the work it portrays.  This property is additional to the relationships between FRBR endeavours present in the classical FRBR data model.'),
(1, $vocabularyId, 'isRepresentationOf', 'is representation of', 'A property linking a particular item to the expression it represents.  This property is additional to the relationships between FRBR endeavours present in the classical FRBR data model.'),
(1, $vocabularyId, 'isSchemeOf', 'is scheme of', 'This property expresses the fact that a scheme contains a concept.'),
(1, $vocabularyId, 'isStoredOn', 'is stored on', 'This property relates a fabio:Item to the medium upon which it is stored.'),
(1, $vocabularyId, 'stores', 'stores', 'This property relates a storage medium to the fabio:Item stored upon it.'),
(1, $vocabularyId, 'usesCalendar', 'uses calendar', 'A property that identifies the calendar system used to specify a date, for example the Chinese, Gregorian, Hebrew, Islamic or Lunar calendar.');
SQL;
        $connection->exec($sql);
    }
}
