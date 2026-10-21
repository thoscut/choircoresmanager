/* eslint-disable */
/* tslint:disable */
// @ts-nocheck
/*
 * ---------------------------------------------------------------
 * ## THIS FILE WAS GENERATED VIA SWAGGER-TYPESCRIPT-API        ##
 * ##                                                           ##
 * ## AUTHOR: acacode                                           ##
 * ## SOURCE: https://github.com/acacode/swagger-typescript-api ##
 * ---------------------------------------------------------------
 */

import {
  AdminGetEditGroupsData,
  AdminGetPublicPageData,
  AdminPostEditGroupsData,
  AdminPostEditGroupsPayload,
  AdminPostPublicPageData,
  AdminPostPublicPagePayload,
  OCSMeta,
} from "./data-contracts";
import { HttpClient, RequestParams } from "./http-client";

export class Ocs<
  SecurityDataType = unknown,
> extends HttpClient<SecurityDataType> {
  /**
   * @description Current settings This endpoint requires admin access
   *
   * @tags admin
   * @name AdminGetEditGroups
   * @summary Get current list of groups allowed to edit scores
   * @request GET:/ocs/v2.php/apps/orchestrascoresmanager/admin/editGroups
   * @secure
   */
  adminGetEditGroups = (params: RequestParams = {}) =>
    this.request<
      AdminGetEditGroupsData,
      {
        ocs: {
          meta: OCSMeta;
          data: any;
        };
      }
    >({
      path: `/ocs/v2.php/apps/orchestrascoresmanager/admin/editGroups`,
      method: "GET",
      secure: true,
      format: "json",
      ...params,
    });
  /**
   * @description Updated settings This endpoint requires admin access This endpoint requires password confirmation
   *
   * @tags admin
   * @name AdminPostEditGroups
   * @summary Update which groups are allowed to edit scores
   * @request POST:/ocs/v2.php/apps/orchestrascoresmanager/admin/editGroups
   * @secure
   */
  adminPostEditGroups = (
    data?: AdminPostEditGroupsPayload,
    params: RequestParams = {},
  ) =>
    this.request<
      AdminPostEditGroupsData,
      {
        ocs: {
          meta: OCSMeta;
          data: any;
        };
      }
    >({
      path: `/ocs/v2.php/apps/orchestrascoresmanager/admin/editGroups`,
      method: "POST",
      body: data,
      secure: true,
      type: "application/json",
      format: "json",
      ...params,
    });
  /**
   * @description Current setting This endpoint requires admin access
   *
   * @tags admin
   * @name AdminGetPublicPage
   * @summary Get whether the public page for freely available scores is enabled
   * @request GET:/ocs/v2.php/apps/orchestrascoresmanager/admin/publicPage
   * @secure
   */
  adminGetPublicPage = (params: RequestParams = {}) =>
    this.request<
      AdminGetPublicPageData,
      {
        ocs: {
          meta: OCSMeta;
          data: any;
        };
      }
    >({
      path: `/ocs/v2.php/apps/orchestrascoresmanager/admin/publicPage`,
      method: "GET",
      secure: true,
      format: "json",
      ...params,
    });
  /**
   * @description Updated setting This endpoint requires admin access This endpoint requires password confirmation
   *
   * @tags admin
   * @name AdminPostPublicPage
   * @summary Enable or disable the public page for freely available scores
   * @request POST:/ocs/v2.php/apps/orchestrascoresmanager/admin/publicPage
   * @secure
   */
  adminPostPublicPage = (
    data: AdminPostPublicPagePayload,
    params: RequestParams = {},
  ) =>
    this.request<
      AdminPostPublicPageData,
      {
        ocs: {
          meta: OCSMeta;
          data: any;
        };
      }
    >({
      path: `/ocs/v2.php/apps/orchestrascoresmanager/admin/publicPage`,
      method: "POST",
      body: data,
      secure: true,
      type: "application/json",
      format: "json",
      ...params,
    });
}
