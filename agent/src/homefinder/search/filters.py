"""Hard filters of the flat search.

They repeat the fields and semantics of Laravel's `App\\Services\\Catalog\\FlatFilters`, so a buyer's request finds
the same flats in the chat as a manager's filter finds in the catalog. The values of the enums are those of
`App\\Enums` stored in the database.
"""

from datetime import date
from enum import StrEnum
from typing import Annotated, Self

from pydantic import BaseModel, ConfigDict, Field, model_validator

MANY_ROOMS = 4
"""The room count that stands for four rooms and more."""


class Finishing(StrEnum):
    ROUGH = "rough"
    WHITE_BOX = "white_box"
    FINISHED = "finished"
    FURNISHED = "furnished"


class FlatStatus(StrEnum):
    AVAILABLE = "available"
    RESERVED = "reserved"
    SOLD = "sold"
    WITHDRAWN = "withdrawn"


class PoiType(StrEnum):
    METRO = "metro"
    SCHOOL = "school"
    KINDERGARTEN = "kindergarten"
    PARK = "park"
    SHOP = "shop"


NearbyPoiType = Annotated[PoiType, Field(description="A kind of place within a kilometer of the complex; not metro")]


class FlatFilters(BaseModel):
    """What a request like «двушка до 9 млн, до метро пешком, сдача не позже 2027» turns into."""

    model_config = ConfigDict(extra="forbid", frozen=True)

    complex_ids: list[int] = []
    rooms: list[Annotated[int, Field(ge=0, le=MANY_ROOMS)]] = []
    """Room counts: 0 for studios and free layouts, MANY_ROOMS for four rooms and more."""
    is_euro: bool | None = None
    """Only euro layouts, only others, or both when None."""
    price_from: Annotated[int, Field(ge=0)] | None = None
    price_to: Annotated[int, Field(ge=0)] | None = None
    area_from: Annotated[float, Field(ge=0)] | None = None
    area_to: Annotated[float, Field(ge=0)] | None = None
    floor_from: Annotated[int, Field(ge=-5, le=200)] | None = None
    floor_to: Annotated[int, Field(ge=-5, le=200)] | None = None
    not_first_floor: bool = False
    not_last_floor: bool = False
    deadline_to: date | None = None
    """The latest completion date; buildings without a date are left out."""
    metro_walk_to: Annotated[int, Field(ge=1, le=60)] | None = None
    """The longest walk to the nearest metro station, in minutes."""
    nearby: list[NearbyPoiType] = []
    """Kinds of places the complex must have within a kilometer: «рядом школа», «у парка»."""
    finishings: list[Finishing] = []
    statuses: list[FlatStatus] = [FlatStatus.AVAILABLE]
    """An empty list means any status."""

    @model_validator(mode="after")
    def _metro_is_not_nearby(self) -> Self:
        if PoiType.METRO in self.nearby:
            raise ValueError("metro is filtered by metro_walk_to, not by nearby")
        return self
