<?php

declare(strict_types=1);

namespace Semitexa\ProjectGraph\Application\Graph;

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
}
