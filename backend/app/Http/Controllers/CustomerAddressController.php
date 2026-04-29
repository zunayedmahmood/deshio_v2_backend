<?php

namespace App\Http\Controllers;

use App\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class CustomerAddressController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:customer');
    }

    /**
     * Get all addresses for authenticated customer
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            $type = $request->query('type'); // shipping, billing, both

            $query = CustomerAddress::forCustomer($customerId);

            if ($type) {
                switch ($type) {
                    case 'shipping':
                        $query->shippingAddresses();
                        break;
                    case 'billing':
                        $query->billingAddresses();
                        break;
                }
            }

            $addresses = $query->latest()->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'addresses' => $addresses,
                    'default_shipping' => CustomerAddress::getDefaultShippingForCustomer($customerId),
                    'default_billing' => CustomerAddress::getDefaultBillingForCustomer($customerId),
                    'total' => $addresses->count(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch addresses',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get specific address
     */
    public function show($id): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            
            $address = CustomerAddress::forCustomer($customerId)->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => [
                    'address' => $address,
                    'formatted_address' => $address->formatted_address,
                    'full_address' => $address->full_address,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Address not found',
                'error' => $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Create new address
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'phone' => 'nullable|string|max:20',
                'address_line_1' => 'required|string|max:255',
                'address_line_2' => 'nullable|string|max:255',
                'city' => 'required|string|max:100',
                'state' => 'required|string|max:100',
                'postal_code' => 'required|string|max:20',
                'country' => 'nullable|string|max:100',
                'landmark' => 'nullable|string|max:255',
                'type' => 'required|in:shipping,billing,both',
                'is_default_shipping' => 'boolean',
                'is_default_billing' => 'boolean',
                'delivery_instructions' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = auth('customer')->id();
            $addressData = $validator->validated();

            // Create address
            $address = CustomerAddress::createAddress($addressData, $customerId);

            // Handle default flags
            if ($request->has('is_default_shipping') && $request->is_default_shipping) {
                $address->makeDefaultShipping();
            }

            if ($request->has('is_default_billing') && $request->is_default_billing) {
                $address->makeDefaultBilling();
            }

            $address->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Address created successfully',
                'data' => [
                    'address' => $address,
                    'formatted_address' => $address->formatted_address,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create address',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update existing address
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'phone' => 'nullable|string|max:20',
                'address_line_1' => 'sometimes|required|string|max:255',
                'address_line_2' => 'nullable|string|max:255',
                'city' => 'sometimes|required|string|max:100',
                'state' => 'sometimes|required|string|max:100',
                'postal_code' => 'sometimes|required|string|max:20',
                'country' => 'nullable|string|max:100',
                'landmark' => 'nullable|string|max:255',
                'type' => 'sometimes|required|in:shipping,billing,both',
                'is_default_shipping' => 'boolean',
                'is_default_billing' => 'boolean',
                'delivery_instructions' => 'nullable|string|max:500',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $customerId = auth('customer')->id();
            $address = CustomerAddress::forCustomer($customerId)->findOrFail($id);

            $updateData = $validator->validated();
            
            // Remove boolean flags from update data, handle separately
            unset($updateData['is_default_shipping'], $updateData['is_default_billing']);
            
            $address->update($updateData);

            // Handle default flags
            if ($request->has('is_default_shipping') && $request->is_default_shipping) {
                $address->makeDefaultShipping();
            }

            if ($request->has('is_default_billing') && $request->is_default_billing) {
                $address->makeDefaultBilling();
            }

            $address->refresh();

            return response()->json([
                'success' => true,
                'message' => 'Address updated successfully',
                'data' => [
                    'address' => $address,
                    'formatted_address' => $address->formatted_address,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update address',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete address
     */
    public function destroy($id): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            $address = CustomerAddress::forCustomer($customerId)->findOrFail($id);

            if (!$address->canDelete()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete the last address. Please add another address first.',
                ], 400);
            }

            // If deleting default address, make another one default
            $wasDefaultShipping = $address->is_default_shipping;
            $wasDefaultBilling = $address->is_default_billing;

            $address->delete();

            // Assign new defaults if needed
            if ($wasDefaultShipping) {
                $newDefault = CustomerAddress::forCustomer($customerId)
                    ->shippingAddresses()
                    ->first();
                if ($newDefault) {
                    $newDefault->makeDefaultShipping();
                }
            }

            if ($wasDefaultBilling) {
                $newDefault = CustomerAddress::forCustomer($customerId)
                    ->billingAddresses()
                    ->first();
                if ($newDefault) {
                    $newDefault->makeDefaultBilling();
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Address deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete address',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Set address as default shipping
     */
    public function setDefaultShipping($id): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            $address = CustomerAddress::forCustomer($customerId)->findOrFail($id);

            if (!$address->isShippingAddress()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This address cannot be used for shipping',
                ], 400);
            }

            $address->makeDefaultShipping();

            return response()->json([
                'success' => true,
                'message' => 'Default shipping address updated',
                'data' => ['address' => $address],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to set default shipping address',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Set address as default billing
     */
    public function setDefaultBilling($id): JsonResponse
    {
        try {
            $customerId = auth('customer')->id();
            $address = CustomerAddress::forCustomer($customerId)->findOrFail($id);

            if (!$address->isBillingAddress()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This address cannot be used for billing',
                ], 400);
            }

            $address->makeDefaultBilling();

            return response()->json([
                'success' => true,
                'message' => 'Default billing address updated',
                'data' => ['address' => $address],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to set default billing address',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Validate delivery area
     */
    public function validateDeliveryArea(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'city' => 'required|string',
                'state' => 'required|string',
                'postal_code' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $city = $request->city;
            $state = $request->state;
            $postalCode = $request->postal_code;

            // Define delivery areas (this can be configured in database)
            $deliveryAreas = [
                'Dhaka' => ['1000', '1100', '1200', '1205', '1206', '1207', '1208', '1209', '1210', '1212', '1213', '1214', '1215', '1216', '1217', '1219', '1220', '1229', '1230'],
                'Chittagong' => ['4000', '4100', '4200', '4210', '4220', '4230'],
                'Sylhet' => ['3100', '3110', '3120', '3130'],
                'Rajshahi' => ['6000', '6100', '6200'],
                'Khulna' => ['9000', '9100', '9200'],
            ];

            $isDeliveryAvailable = false;
            $estimatedDays = null;
            $deliveryCharge = 0;

            foreach ($deliveryAreas as $area => $codes) {
                if (str_contains(strtolower($city), strtolower($area)) || 
                    str_contains(strtolower($state), strtolower($area))) {
                    if (in_array($postalCode, $codes)) {
                        $isDeliveryAvailable = true;
                        $estimatedDays = $area === 'Dhaka' ? '1-2' : '2-4';
                        $deliveryCharge = $area === 'Dhaka' ? 60 : 120;
                        break;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'is_delivery_available' => $isDeliveryAvailable,
                    'estimated_delivery_days' => $estimatedDays,
                    'delivery_charge' => $deliveryCharge,
                    'message' => $isDeliveryAvailable 
                        ? "Delivery available in {$estimatedDays} business days"
                        : 'Delivery not available in this area',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to validate delivery area',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get address suggestions based on input
     */
    public function getSuggestions(Request $request): JsonResponse
    {
        try {
            $query = $request->query('q');
            $type = $request->query('type', 'city'); // city, area, postal_code

            if (strlen($query) < 2) {
                return response()->json([
                    'success' => true,
                    'data' => ['suggestions' => []],
                ]);
            }

            $suggestions = [];

            switch ($type) {
                case 'city':
                    $suggestions = [
                        'Dhaka', 'Chittagong', 'Sylhet', 'Rajshahi', 'Khulna', 
                        'Comilla', 'Rangpur', 'Barisal', 'Mymensingh', 'Gazipur',
                        'Narayanganj', 'Savar', 'Tongi', 'Cox Bazar'
                    ];
                    break;

                case 'area':
                    $suggestions = [
                        'Dhanmondi', 'Gulshan', 'Banani', 'Uttara', 'Mirpur',
                        'Wari', 'Old Dhaka', 'New Market', 'Elephant Road',
                        'Tejgaon', 'Mohammadpur', 'Lalmatia', 'Ramna'
                    ];
                    break;

                case 'postal_code':
                    $suggestions = [
                        '1000', '1205', '1212', '1213', '1215', '1216',
                        '4000', '3100', '6000', '9000'
                    ];
                    break;
            }

            $filtered = array_filter($suggestions, function($item) use ($query) {
                return stripos($item, $query) !== false;
            });

            return response()->json([
                'success' => true,
                'data' => [
                    'suggestions' => array_values($filtered),
                    'type' => $type,
                    'query' => $query,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get suggestions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}