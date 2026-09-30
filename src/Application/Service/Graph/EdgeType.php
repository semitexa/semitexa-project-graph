<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Service\Graph;

enum EdgeType: string
{
    case Extends            = 'extends';
    case Implements         = 'implements';
    case Uses               = 'uses';
    case Imports            = 'imports';
    case Calls              = 'calls';
    case Instantiates       = 'instantiates';
    case Returns            = 'returns';
    case Accepts            = 'accepts';
    case DefinedIn          = 'defined_in';
    case InFile             = 'in_file';
    case InModule           = 'in_module';
    case InNamespace        = 'in_namespace';
    case Handles            = 'handles';
    case Produces           = 'produces';
    case ServesRoute        = 'serves_route';
    case InjectsReadonly    = 'injects_readonly';
    case InjectsMutable     = 'injects_mutable';
    case InjectsFactory     = 'injects_factory';
    case InjectsConfig      = 'injects_config';
    case ListensTo          = 'listens_to';
    case Emits              = 'emits';
    case SatisfiesContract  = 'satisfies_contract';
    case RequiresPermission = 'requires_permission';
    case RequiresCapability = 'requires_capability';
    case TenantIsolated     = 'tenant_isolated';
    case PipelinePhase      = 'pipeline_phase';
    case RendersSlot        = 'renders_slot';
    case ProvidesData       = 'provides_data';
    case MapsToTable        = 'maps_to_table';
    case HasRelation        = 'has_relation';
    case ExposesApi         = 'exposes_api';
    case ScheduledAs        = 'scheduled_as';
    case ComposedOf         = 'composed_of';
    case ExtendsModule      = 'extends_module';
    case Authenticates      = 'authenticates';
    case Tests              = 'tests';

    case BelongsToDomain    = 'belongs_to_domain';
    case ParticipatesInFlow = 'participates_in_flow';
    case TriggersFlow       = 'triggers_flow';
    case PrecedesInFlow     = 'precedes_in_flow';
    case CrossesBoundary    = 'crosses_boundary';
    case IsHotspot          = 'is_hotspot';
    case CoupledTo          = 'coupled_to';
    case IntentFor          = 'intent_for';

    case PublishesTo        = 'publishes_to';
    case ConsumesFrom       = 'consumes_from';
    case StreamsTo          = 'streams_to';
    case HasSchema          = 'has_schema';
    case IsAggregateOf      = 'is_aggregate_of';
    case ReplaysVia         = 'replays_via';
    case RoutesCommandTo    = 'routes_command_to';
    case DeadLettersTo      = 'dead_letters_to';
    case RetriesVia         = 'retries_via';

    case DocumentedBy       = 'documented_by';
    case HasExample         = 'has_example';
    case ReferencesADR      = 'references_adr';
    case Supersedes         = 'supersedes';

    /**
     * Deliberately a match with no default arm: a new case that is not
     * classified here fails EdgeClassTest instead of silently falling into
     * some bucket.
     */
    public function edgeClass(): EdgeClass
    {
        return match ($this) {
            self::Handles, self::Produces, self::ServesRoute,
            self::InjectsReadonly, self::InjectsMutable, self::InjectsFactory, self::InjectsConfig,
            self::ListensTo, self::SatisfiesContract,
            self::RequiresPermission, self::RequiresCapability, self::Authenticates, self::TenantIsolated,
            self::PipelinePhase, self::RendersSlot, self::ProvidesData,
            self::MapsToTable, self::HasRelation, self::ExposesApi, self::ScheduledAs, self::ExtendsModule,
            self::PublishesTo, self::ConsumesFrom, self::StreamsTo, self::HasSchema, self::IsAggregateOf,
            self::ReplaysVia, self::RoutesCommandTo, self::DeadLettersTo, self::RetriesVia
                => EdgeClass::Wiring,

            self::Extends, self::Implements, self::Uses, self::ComposedOf, self::Imports,
            self::Calls, self::Instantiates, self::Returns, self::Accepts, self::Emits, self::Tests
                => EdgeClass::CodeReference,

            self::BelongsToDomain, self::ParticipatesInFlow, self::TriggersFlow, self::PrecedesInFlow,
            self::CrossesBoundary, self::IsHotspot, self::CoupledTo, self::IntentFor
                => EdgeClass::Inferred,

            self::DocumentedBy, self::HasExample, self::ReferencesADR, self::Supersedes
                => EdgeClass::Documentation,

            self::DefinedIn, self::InFile, self::InModule, self::InNamespace
                => EdgeClass::Structural,
        };
    }
}
